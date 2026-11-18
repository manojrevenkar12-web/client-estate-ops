"""Plays the estate's history through one way of running it and scores the outcome against the world's truth.

"today" is the agency's current process, run by people: a monthly patch day per client, a fast reaction only to
critical advisories, manual smoke tests, and an uptime tool that pages on any failed probe. Every other design is
the product (estate/, in PHP) with one of the overlays in config/estate.json, asked for its decisions over stdio.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from datetime import datetime, timedelta

from .keyed import rng
from .product import Product
from .world import App, World, iso, vkey

SEVERITIES = ("critical", "high", "medium", "low")


@dataclass
class Result:
    design: str
    deploys: int = 0
    changes_applied: int = 0
    ci_rejections: int = 0
    review_rejections: int = 0
    canary_runs: int = 0
    canary_true_rollbacks: int = 0
    canary_false_rollbacks: int = 0
    canary_missed: int = 0
    broken_deploys: int = 0
    downtime_minutes: float = 0.0
    degraded_minutes: float = 0.0
    pages: int = 0
    noise_pages: int = 0
    outage_pages: int = 0
    deploy_rollbacks_auto: int = 0
    outages: int = 0
    outages_missed: int = 0
    outage_detect_minutes: list[float] = field(default_factory=list)
    person_hours: dict[str, float] = field(default_factory=dict)
    held_for_freeze: int = 0
    reviews_deferred: int = 0
    review_backlog: list[int] = field(default_factory=list)
    watch_rollbacks: int = 0
    watch_false_rollbacks: int = 0
    exposure: dict[str, dict] = field(default_factory=dict)
    freshness: float = 0.0
    majors_behind: int = 0
    deploys_in_freeze: int = 0
    product_calls: int = 0

    def hours(self, kind: str, h: float) -> None:
        self.person_hours[kind] = self.person_hours.get(kind, 0.0) + h

    def summary(self, apps: int, days: int) -> dict:
        d = {k: v for k, v in self.__dict__.items() if k not in ("outage_detect_minutes", "review_backlog", "reviews_deferred")}
        d["review_backlog_avg"] = round(sum(self.review_backlog) / len(self.review_backlog), 1) if self.review_backlog else 0
        d["person_hours_total"] = round(sum(self.person_hours.values()), 1)
        d["person_hours"] = {k: round(v, 1) for k, v in sorted(self.person_hours.items())}
        months = days / 30.4
        d["broken_minutes_per_app_month"] = round((self.downtime_minutes + self.degraded_minutes) / apps / months, 2)
        d["downtime_minutes"] = round(self.downtime_minutes, 1)
        d["degraded_minutes"] = round(self.degraded_minutes, 1)
        d["noise_page_share"] = round(self.noise_pages / self.pages, 3) if self.pages else 0.0
        det = sorted(self.outage_detect_minutes)
        d["outage_detect_minutes_median"] = round(det[len(det) // 2], 1) if det else None
        d["pages_per_week"] = round(self.pages / (days / 7), 1)
        return d


class Run:
    def __init__(self, world: World, design: str, product: Product | None):
        self.w = world
        self.design = design
        self.product = product
        self.r = Result(design)
        self.people = world.cfg["people"]
        self.prod: dict[str, dict[str, str]] = {a.id: dict(a.deps) for a in world.apps}
        self.history: dict[str, dict[str, list[tuple[datetime, str]]]] = {
            a.id: {p: [(world.start, v)] for p, v in a.deps.items()} for a in world.apps}
        self.skip: dict[str, dict[str, set[str]]] = {a.id: {} for a in world.apps}
        self.jobs: list[dict] = []
        self.incidents: list[dict] = []

    # ---- the loop ------------------------------------------------------------------------------------------------

    def play(self) -> Result:
        w = self.w
        for d in range((w.end - w.start).days):
            day = w.start + timedelta(days=d)
            morning = day + timedelta(hours=9)
            if self.design == "today":
                self._people_day(morning)
            else:
                self._product_day(morning)
            self._probes(day)
        self._score()
        if self.product is not None:
            self.r.product_calls = self.product.calls
        return self.r

    def _product_day(self, now: datetime) -> None:
        w = self.w
        apps = []
        for a in w.apps:
            deps = []
            for name, v in self.prod[a.id].items():
                deps.append({"ecosystem": a.eco, "name": name, "version": v,
                             "available": w.available(a.eco, name, v, now),
                             "skip": sorted(self.skip[a.id].get(name, set()))})
            apps.append({"id": a.id, "freeze": [{"from": iso(f), "to": iso(t)} for f, t in a.freezes],
                         "dependencies": deps})
        advisories = [x.osv(now) for x in w.advisories_at(now)]
        assert self.product is not None
        plans = self.product.ask({"op": "plan_many", "apps": apps, "advisories": advisories, "now": iso(now)})["plans"]
        deferred = 0
        for a in w.apps:
            slot = now + timedelta(hours=1)
            for job in plans.get(a.id, []):
                if job["lane"] == "held":
                    self.r.held_for_freeze += sum(1 for h in job["held"] if "freeze" in h["reason"])
                    continue
                if job["lane"] == "deferred":
                    deferred += 1
                    continue
                self._job(a, job, slot)
                slot += timedelta(minutes=40)
        if deferred:
            self.r.review_backlog.append(deferred)

    def _people_day(self, now: datetime) -> None:
        """The agency today: a monthly patch day on the first Tuesday, and critical advisories handled when someone
        reads about them."""
        w = self.w
        first_tuesday = now.day <= 7 and now.isoweekday() == 2
        for a in w.apps:
            slot = now + timedelta(hours=1)
            if first_tuesday and not a.frozen(now):
                changes = []
                for name, v in self.prod[a.id].items():
                    newer = [x for x in w.available(a.eco, name, v, now) if not x["yanked"]
                             and vkey(x["version"])[0] == vkey(v)[0]]
                    if newer:
                        to = max(newer, key=lambda x: vkey(x["version"]))["version"]
                        changes.append({"name": name, "from": v, "to": to, "fixes": []})
                self.r.hours("patch days", self.people["patch_day_hours_per_app"] + self.people["hours_per_change"] * len(changes))
                if changes:
                    self._job(a, {"lane": "patch-day", "changes": changes, "canary": False, "manual": True}, slot)
                    slot += timedelta(minutes=40)
            for adv in w.advisories:
                if adv.severity != "critical" or adv.package not in self.prod[a.id] or adv.eco != a.eco:
                    continue
                react = adv.published + timedelta(days=rng(w.seed, "react", a.id, adv.id).uniform(*self.people["critical_reaction_days"]))
                if not (now - timedelta(days=1) < react <= now):
                    continue
                v = self.prod[a.id][adv.package]
                if v not in adv.affected or adv.fixed is None or adv.fixed_at is None or adv.fixed_at > now:
                    continue
                fixes = [x for x in w.available(a.eco, adv.package, v, now) if not x["yanked"] and x["version"] not in adv.affected
                         and vkey(x["version"])[0] == vkey(v)[0]]
                if not fixes:
                    continue
                to = min(fixes, key=lambda x: vkey(x["version"]))["version"]
                self.r.hours("critical patches", 1.0)
                self._job(a, {"lane": "hotfix", "changes": [{"name": adv.package, "from": v, "to": to, "fixes": [adv.id]}],
                              "canary": False, "manual": True}, slot)
                slot += timedelta(minutes=40)

    # ---- one job -------------------------------------------------------------------------------------------------

    def _job(self, a: App, job: dict, at: datetime) -> None:
        w, p = self.w, self.people
        record = {"id": f"{a.id}-{iso(at)}", "app": a.id, "lane": job["lane"], "created_at": iso(at), "changes": job["changes"],
                  "status": "open", "verdict": None}
        self.jobs.append(record)
        keep = []
        if job["lane"] == "review":
            self.r.hours("major reviews", p["review_hours"])
        for c in job["changes"]:
            if self.prod[a.id].get(c["name"]) != c["from"]:
                continue  # an earlier job today moved it
            br = w.breaks(a, c["name"], c["from"], c["to"])
            if br is not None and job["lane"] == "review" and rng(w.seed, "review", a.id, c["name"], c["to"]).random() < p["review_catch"][br["kind"]]:
                self._reject(a, c, "review")
                self.r.review_rejections += 1
                continue
            if br is not None and w.tests_catch(a, c["name"], c["to"], br["kind"]):
                self._reject(a, c, "ci")
                self.r.ci_rejections += 1
                self.r.hours("CI failures", p["ci_failure_hours"])
                continue
            if br is not None and job.get("manual") and rng(w.seed, "smoke", a.id, c["name"], c["to"]).random() < p["smoke_test_catch"][br["kind"]]:
                self._reject(a, c, "smoke test")
                self.r.hours("CI failures", p["ci_failure_hours"])
                continue
            keep.append((c, br))
        if not keep:
            record["status"] = "rejected"
            return
        bad = [(c, br) for c, br in keep if br is not None]
        if job.get("canary"):
            verdict = self._canary(a, job["canary"], bad, at)
            record["verdict"] = verdict
            if verdict["verdict"] == "rollback":
                self.r.hours("canary rollbacks", p["canary_rollback_hours"])
                if bad:
                    self.r.canary_true_rollbacks += 1
                    for c, _ in bad:
                        self._reject(a, c, "canary")
                else:
                    self.r.canary_false_rollbacks += 1
                record["status"] = "rolled back at canary"
                return
            if bad:
                self.r.canary_missed += 1
        record["status"] = "deployed"
        self._deploy(a, [c for c, _ in keep], at, record)
        watched = None
        if job.get("watch_hours") and self.product is not None and not any(br["kind"] == "crash" for _, br in bad):
            hours = float(job["watch_hours"])
            if self._watch(a, bad, at, hours)["verdict"] == "rollback":
                watched = at + timedelta(hours=hours)
                if not bad:  # a false alarm: the deploy is reverted and tried again another day
                    self.r.watch_false_rollbacks += 1
                    self.r.hours("canary rollbacks", p["canary_rollback_hours"])
                    for c, _ in keep:
                        if self.prod[a.id][c["name"]] == c["to"]:
                            self.prod[a.id][c["name"]] = c["from"]
                            self.history[a.id][c["name"]].append((watched, c["from"]))
                    record["status"] = "rolled back after watch"
                    return
                self.r.watch_rollbacks += 1
        if bad:
            self._incident(a, keep, bad, at, record, watched)

    def _reject(self, a: App, c: dict, why: str) -> None:
        self.skip[a.id].setdefault(c["name"], set()).add(c["to"])

    def _canary(self, a: App, canary: dict, bad: list, at: datetime) -> dict:
        w = self.w
        share = float(canary["traffic_share"])
        minutes = float(canary["minutes"])
        crash = any(br["kind"] == "crash" for _, br in bad)
        extra = min(0.5, sum(br["extra_error"] for _, br in bad if br["kind"] == "subtle"))
        r = rng(w.seed, "canary", a.id, iso(at))
        extensions = 0
        self.r.canary_runs += 1
        while True:
            total = a.rpm * minutes
            n_c = int(total * share)
            n_b = int(total - n_c)
            drift = r.uniform(-0.15, 0.15) * a.base_error  # the canary's slice of traffic is never identical
            req = {"op": "judge", "baseline": {"requests": n_b, "errors": _binomial(r, n_b, a.base_error)},
                   "canary": {"requests": n_c, "errors": _binomial(r, n_c, max(0.0, a.base_error + drift + extra)),
                              "health_failed": crash}, "extensions": extensions}
            assert self.product is not None
            v = self.product.ask(req)
            if v["verdict"] != "extend":
                return v
            extensions += 1
            minutes *= 2

    def _watch(self, a: App, bad: list, at: datetime, hours: float) -> dict:
        """Production errors in the hours after a full rollout against the same hours before it."""
        r = rng(self.w.seed, "watch", a.id, iso(at))
        extra = min(0.5, sum(br["extra_error"] for _, br in bad if br["kind"] == "subtle"))
        n = int(a.rpm * 60 * hours)
        drift = r.uniform(-0.1, 0.1) * a.base_error  # traffic differs from one afternoon to the next
        assert self.product is not None
        return self.product.ask({"op": "judge", "baseline": {"requests": n, "errors": _binomial(r, n, a.base_error)},
                                 "canary": {"requests": n, "errors": _binomial(r, n, max(0.0, a.base_error + drift + extra))},
                                 "extensions": 99})

    def _deploy(self, a: App, changes: list[dict], at: datetime, record: dict) -> None:
        self.r.deploys += 1
        if a.frozen(at):
            self.r.deploys_in_freeze += 1
        for c in changes:
            self.prod[a.id][c["name"]] = c["to"]
            self.history[a.id][c["name"]].append((at, c["to"]))
            self.r.changes_applied += 1

    def _incident(self, a: App, keep: list, bad: list, at: datetime, record: dict, watched: datetime | None = None) -> None:
        """A breaking change reached production: how long until it is rolled back?"""
        w, p = self.w, self.people
        self.r.broken_deploys += 1
        crash = any(br["kind"] == "crash" for _, br in bad)
        if crash:
            rounds = [{"t": iso(at + timedelta(minutes=m)), "failed": list(w.cfg["probes"]["regions"])} for m in range(1, 61)]
            page = self._pages(rounds, [{"from": iso(at), "to": iso(at + timedelta(minutes=5)), "job": record["id"]}])
            first = page[0] if page else {"at": iso(at + timedelta(minutes=60)), "action": "page", "cause": "outage"}
            seen = datetime.fromisoformat(first["at"].replace("Z", "+00:00"))
            if first["action"] == "rollback":
                self.r.deploy_rollbacks_auto += 1
                back = seen + timedelta(minutes=p["auto_rollback_minutes"])
                self.r.hours("incidents", p["incident_hours"] / 2)
            else:
                self.r.pages += 1
                self.r.hours("pages", p["page_hours"])
                ack = rng(w.seed, "ack", a.id, iso(at)).uniform(*p["ack_minutes"])
                back = seen + timedelta(minutes=ack + p["manual_rollback_minutes"])
                self.r.hours("incidents", p["incident_hours"])
            self.r.downtime_minutes += (back - at).total_seconds() / 60
        else:
            hours = min(br["report_hours"] for _, br in bad)
            back = at + timedelta(hours=hours, minutes=p["manual_rollback_minutes"])
            if watched is not None:
                back = min(back, watched + timedelta(minutes=p["auto_rollback_minutes"]))
            back = min(back, w.end)
            self.r.degraded_minutes += (back - at).total_seconds() / 60
            self.r.hours("incidents", p["incident_hours"])
        self.incidents.append({"id": f"inc-{record['id']}", "app": a.id, "opened": iso(at), "at": iso(back),
                               "cause": "deploy", "action": "rollback"})
        for c, _ in keep:  # the whole deploy is reverted; the culprit is not retried
            if self.prod[a.id][c["name"]] == c["to"]:
                self.prod[a.id][c["name"]] = c["from"]
                self.history[a.id][c["name"]].append((back, c["from"]))
        for c, _ in bad:
            self._reject(a, c, "production")
        record["status"] = "rolled back in production"

    # ---- paging ------------------------------------------------------------------------------------------------

    def _pages(self, rounds: list[dict], deploys: list[dict]) -> list[dict]:
        if self.product is None:  # today: the uptime tool pages on any failed probe
            return self._any(rounds)
        return self.product.ask({"op": "pages", "rounds": rounds, "deploys": deploys})["pages"]

    @staticmethod
    def _any(rounds: list[dict]) -> list[dict]:
        out, open_ = [], False
        for r in rounds:
            if r["failed"] and not open_:
                out.append({"at": r["t"], "opened": r["t"], "action": "page", "cause": "outage"})
                open_ = True
            elif not r["failed"]:
                open_ = False
        return out

    def _probes(self, day: datetime) -> None:
        w, p = self.w, self.people
        for a in w.apps:
            events = w.probe_events(a, day)
            if not events:
                continue
            rounds = []
            for e in events:
                for m in range(e["minutes"]):
                    rounds.append({"t": iso(e["at"] + timedelta(minutes=m)), "failed": e["regions"]})
                rounds.append({"t": iso(e["at"] + timedelta(minutes=e["minutes"])), "failed": []})
            rounds.sort(key=lambda r: r["t"])
            pages = self._pages(rounds, [])
            for e in events:
                if e["kind"] == "outage":
                    self.r.outages += 1
            for pg in pages:
                at = datetime.fromisoformat(pg["at"].replace("Z", "+00:00"))
                ev = next((e for e in events if e["at"] <= at <= e["at"] + timedelta(minutes=e["minutes"])), None)
                self.r.pages += 1
                self.r.hours("pages", p["page_hours"])
                if ev is None or ev["kind"] == "blip":
                    self.r.noise_pages += 1
                else:
                    self.r.outage_pages += 1
                    self.r.outage_detect_minutes.append((at - ev["at"]).total_seconds() / 60)
                    self.r.hours("incidents", p["incident_hours"])
                    self.incidents.append({"id": f"out-{a.id}-{pg['at']}", "app": a.id, "opened": iso(ev["at"]),
                                           "at": pg["at"], "cause": "outage", "action": "page"})
            paged = {datetime.fromisoformat(pg["at"].replace("Z", "+00:00")) for pg in pages}
            for e in events:
                if e["kind"] == "outage" and not any(e["at"] <= t <= e["at"] + timedelta(minutes=e["minutes"]) for t in paged):
                    self.r.outages_missed += 1

    # ---- scoring -----------------------------------------------------------------------------------------------

    def _score(self) -> None:
        w = self.w
        exposure: dict[str, list[float]] = {s: [] for s in SEVERITIES}
        open_at_end = {s: 0 for s in SEVERITIES}
        for a in w.apps:
            for adv in w.advisories:
                if adv.eco != a.eco or adv.package not in self.history[a.id]:
                    continue
                fix_known = adv.fixed_at if adv.fixed_at is not None else w.end
                since = max(adv.published, fix_known)  # the controllable part: once a fix exists and is announced
                if since >= w.end:
                    continue
                days = self._exposed_days(self.history[a.id][adv.package], adv.affected, since, w.end)
                if days is None:
                    continue
                exposure[adv.severity].append(days)
                if self.prod[a.id][adv.package] in adv.affected:
                    open_at_end[adv.severity] += 1
        for s in SEVERITIES:
            xs = sorted(exposure[s])
            self.r.exposure[s] = {
                "pairs": len(xs), "app_days": round(sum(xs), 1),
                "median_days": round(xs[len(xs) // 2], 2) if xs else None,
                "p90_days": round(xs[min(len(xs) - 1, int(math.ceil(0.9 * len(xs))) - 1)], 2) if xs else None,
                "open_at_end": open_at_end[s]}
        fresh = total = behind = 0
        for a in w.apps:
            for name, v in self.prod[a.id].items():
                pkg = w.packages[f"{a.eco}:{name}"]
                pub = [x for x in pkg.published(w.end) if x.yanked_at is None or x.yanked_at > w.end]
                same = [x for x in pub if vkey(x.version)[0] == vkey(v)[0]]
                total += 1
                if same and max(vkey(x.version) for x in same) <= vkey(v):
                    fresh += 1
                if pub and max(vkey(x.version)[0] for x in pub) > vkey(v)[0]:
                    behind += 1
        self.r.freshness = round(fresh / total, 3)
        self.r.majors_behind = behind

    @staticmethod
    def _exposed_days(hist: list[tuple[datetime, str]], affected: set[str], since: datetime, end: datetime) -> float | None:
        """Days in [since, end) during which production ran an affected version; None if it never did."""
        hist = sorted(hist)
        total = 0.0
        touched = False
        for i, (t, v) in enumerate(hist):
            nxt = hist[i + 1][0] if i + 1 < len(hist) else end
            a, b = max(t, since), min(nxt, end)
            if v in affected and b > a:
                total += (b - a).total_seconds() / 86400
                touched = True
            elif v in affected and t <= since < nxt:
                touched = True
        return total if touched else None


def _binomial(r, n: int, p: float) -> int:
    if n <= 0 or p <= 0:
        return 0
    mean = n * p
    if mean < 30:  # Poisson is close enough for rare errors and is cheap
        k, acc, limit = 0, 1.0, math.exp(-mean)
        while True:
            acc *= r.random()
            if acc <= limit:
                return min(k, n)
            k += 1
    return max(0, min(n, int(round(r.gauss(mean, math.sqrt(mean * (1 - p)))))))
