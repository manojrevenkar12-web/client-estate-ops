"""Cloud spend across the client accounts, with the truth about each resource, and how each way of auditing it does.

Every resource is one of: busy and needed; forgotten (safe to delete); seasonal (idle now, needed for a campaign in a
few weeks); idle staging (needed in office hours only); an unattached volume (mostly forgotten, sometimes detached
for a migration and still needed); an oversized VM (usually safe to shrink, sometimes spiky); an orphaned snapshot.
"""

from __future__ import annotations

from .keyed import pick, rng, uniform
from .world import World

KINDS = {"busy": 0.33, "waste": 0.22, "seasonal": 0.12, "staging": 0.18, "oversized": 0.15}


def resources(w: World) -> list[dict]:
    c = w.cfg["cost"]
    shares = {"busy": 1 - c["waste_share"] - c["seasonal_share"] - c["staging_share"] - c["oversized_share"],
              "waste": c["waste_share"], "seasonal": c["seasonal_share"], "staging": c["staging_share"],
              "oversized": c["oversized_share"]}
    out = []
    for client in w.clients:
        r = rng(w.seed, "cost", client["id"])
        provider = "aws" if r.random() < w.cfg["aws_share"] else "gcp"
        for i in range(r.randint(*c["resources_per_client"])):
            rr = rng(w.seed, "resource", client["id"], i)
            truth = pick(rr, shares)
            eur = round(uniform(rr, c["monthly_eur"]), 2)
            base = {"id": f"{provider}-{client['id']}-{i:02d}", "client": client["id"], "provider": provider,
                    "monthly_eur": eur, "tags": {}}
            if truth == "busy":
                res = {**base, "kind": rr.choice(["vm", "db"]), "cpu_p95": rr.uniform(35, 85), "cpu_p99": rr.uniform(70, 100),
                       "requests_14d": rr.randint(20_000, 4_000_000)}
            elif truth == "waste":
                kind = pick(rr, {"vm": 0.4, "volume": 0.35, "snapshot": 0.25})
                res = {**base, "kind": kind, "cpu_p95": rr.uniform(0, 2), "requests_14d": 0, "attached": False,
                       "age_days": rr.randint(100, 700), "source_exists": False, "last_year_requests_same_weeks": 0}
                if kind == "volume" and rr.random() < 0.15:
                    truth = "migrating"  # detached on purpose and still needed: deleting it loses data
            elif truth == "seasonal":
                res = {**base, "kind": "vm", "cpu_p95": rr.uniform(0, 2), "requests_14d": 0,
                       "last_year_requests_same_weeks": rr.randint(40_000, 900_000)}
                if rr.random() < 0.2:
                    res["tags"] = {"keep": True}
            elif truth == "staging":
                res = {**base, "kind": "staging" if rr.random() < 0.6 else "vm", "cpu_p95": rr.uniform(0, 2),
                       "requests_14d": 0, "tags": {"env": "staging"}}
            else:
                spiky = rr.random() < 0.1
                res = {**base, "kind": "vm", "cpu_p95": rr.uniform(4, 18), "requests_14d": rr.randint(5_000, 900_000),
                       "cpu_p99": rr.uniform(80, 100) if spiky else rr.uniform(20, 50)}
                truth = "spiky" if spiky else "oversized"
            res["truth"] = truth
            out.append(res)
    return out


# What each action is truly worth per resource kind of truth: (monthly saving share, wrong?)
CORRECT = {
    ("waste", "delete"): 1.0, ("waste", "snapshot_and_delete"): 0.8,
    ("staging", "schedule"): 0.65,
    ("oversized", "downsize"): 0.5,
}
WRONG = {("seasonal", "delete"), ("staging", "delete"), ("migrating", "delete"), ("spiky", "downsize"),
         ("busy", "delete"), ("busy", "downsize")}


def score(method: str, rs: list[dict], findings: list[dict]) -> dict:
    by = {r["id"]: r for r in rs}
    saved = wrong_eur = 0.0
    wrong = 0
    wrong_kinds: dict[str, int] = {}
    for f in findings:
        r = by[f["resource"]]
        key = (r["truth"], f["action"])
        if key in CORRECT:
            saved += r["monthly_eur"] * CORRECT[key]
        elif key in WRONG:
            wrong += 1
            wrong_eur += r["monthly_eur"]
            wrong_kinds[r["truth"]] = wrong_kinds.get(r["truth"], 0) + 1
    possible = sum(r["monthly_eur"] * max((v for (t, _), v in CORRECT.items() if t == r["truth"]), default=0) for r in rs)
    return {"method": method, "resources": len(rs), "monthly_eur_total": round(sum(r["monthly_eur"] for r in rs), 2),
            "findings": len(findings), "monthly_saving_eur": round(saved, 2), "possible_saving_eur": round(possible, 2),
            "wrong_actions": wrong, "wrong_action_kinds": wrong_kinds, "wrong_action_monthly_eur": round(wrong_eur, 2)}


def public(rs: list[dict]) -> list[dict]:
    """What the auditor may see: the inventory and its metrics, never the truth."""
    return [{k: v for k, v in r.items() if k != "truth"} for r in rs]
