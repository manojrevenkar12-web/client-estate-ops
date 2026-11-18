"""python -m estate_lab.study --world dev|heldout: plays every design through one world and writes reports/<world>.json.
The held-out world runs once: a lock file refuses a second run."""

from __future__ import annotations

import argparse
import json
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

from . import cost, estimates
from .designs import Run
from .product import Product
from .world import ROOT, World, iso, load

DESIGNS = ["today", "auto_merge", "gated", "gated_no_canary", "gated_no_watch", "gated_no_fast_lane", "gated_naive_pager"]
PROPOSED = "gated"


def run(name: str, cfg: dict, designs: list[str] = DESIGNS) -> tuple[dict, dict]:
    t0 = time.time()
    w = World(cfg)
    report: dict = {"world": name, "seed": w.seed, "days": cfg["days"], "clients": len(w.clients), "apps": len(w.apps),
                    "stacks": _count(a.stack for a in w.apps), "dependencies": sum(len(a.deps) for a in w.apps),
                    "packages": len(w.packages), "releases": sum(len(p.releases) for p in w.packages.values()),
                    "regressions": sum(r.regression for p in w.packages.values() for r in p.releases),
                    "advisories": len(w.advisories), "advisory_severity": _count(a.severity for a in w.advisories),
                    "designs": [], "proposed": PROPOSED}
    runs: dict[str, Run] = {}
    for d in designs:
        product = None if d == "today" else Product(d)
        r = Run(w, d, product)
        r.play()
        if product is not None:
            product.close()
        runs[d] = r
        report["designs"].append(r.r.summary(len(w.apps), cfg["days"]))

    rs = cost.resources(w)
    report["cost"] = [cost.score("no audit (today)", rs, [])]
    for design, label in (("naive_cost", "delete anything idle (common script)"), (PROPOSED, "guarded audit")):
        with Product(design) as p:
            findings = p.ask({"op": "audit", "resources": cost.public(rs)})["findings"]
        report["cost"].append(cost.score(label, rs, findings))
        if design == PROPOSED:
            guarded_findings = findings

    rows = estimates.study(cfg, w.seed)
    report["estimates"] = estimates.score(rows, cfg)
    report["gate"] = gate(report)
    report["took_seconds"] = round(time.time() - t0, 1)
    sandbox = snapshot(w, runs.get(PROPOSED), guarded_findings, rs)
    return report, sandbox


def gate(rep: dict) -> dict:
    g = json.loads((ROOT / "config" / "estate.json").read_text())["gate"]
    d = next(x for x in rep["designs"] if x["design"] == PROPOSED)
    checks = []

    def check(ok: bool, text: str) -> None:
        checks.append(("ok " if ok else "FAIL ") + text)

    crit = d["exposure"]["critical"]["p90_days"] or 0.0
    check(crit <= g["max_critical_exposure_days_p90"],
          f"critical advisories: p90 {crit:.1f} days exposed after a fix existed (max {g['max_critical_exposure_days_p90']})")
    check(d["broken_minutes_per_app_month"] <= g["max_broken_deploy_minutes_per_app_month"],
          f"broken-deploy minutes {d['broken_minutes_per_app_month']:.1f} per app-month (max {g['max_broken_deploy_minutes_per_app_month']})")
    check(d["noise_page_share"] <= g["max_noise_page_share"],
          f"noise pages {100 * d['noise_page_share']:.0f}% (max {100 * g['max_noise_page_share']:.0f}%)")
    guarded = rep["cost"][-1]
    check(guarded["wrong_actions"] <= g["max_wrong_cost_deletions"],
          f"cost findings that would remove something needed: {guarded['wrong_actions']} (max {g['max_wrong_cost_deletions']})")
    est = rep["estimates"][-1]
    check(est["overrun_share"] <= g["max_estimate_underruns_share"],
          f"estimates overrun by more than 15%: {100 * est['overrun_share']:.0f}% (max {100 * g['max_estimate_underruns_share']:.0f}%)")
    return {"design": PROPOSED, "pass": all(c.startswith("ok") for c in checks), "checks": checks}


def snapshot(w: World, r: Run | None, findings: list[dict], rs: list[dict]) -> dict:
    """The sandbox the console and API serve: the proposed design's estate at the end of the history."""
    if r is None:
        return {}
    by_res = {x["id"]: x for x in rs}
    return {
        "as_of": iso(w.end),
        "clients": w.clients,
        "apps": [{"id": a.id, "client": a.client, "name": a.name, "stack": a.stack, "provider": a.provider,
                  "url": f"https://{a.id}.example", "freeze": [{"from": iso(f), "to": iso(t)} for f, t in a.freezes],
                  "dependencies": [{"ecosystem": a.eco, "name": n, "version": v} for n, v in sorted(r.prod[a.id].items())]}
                 for a in w.apps],
        "advisories": [x.osv(w.end) for x in w.advisories],
        "jobs": r.jobs[-1500:],
        "incidents": r.incidents[-400:],
        "findings": [{**f, "provider": by_res[f["resource"]]["provider"]} for f in findings],
    }


def _count(xs) -> dict[str, int]:
    out: dict[str, int] = {}
    for x in xs:
        out[x] = out.get(x, 0) + 1
    return dict(sorted(out.items()))


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--world", default="dev", choices=["dev", "heldout"])
    ap.add_argument("--apps", type=int, default=None, help="a smaller estate for quick checks")
    args = ap.parse_args()
    reports = ROOT / "reports"
    reports.mkdir(exist_ok=True)
    lock = reports / "heldout.lock"
    if args.world == "heldout" and lock.exists():
        sys.exit(f"the held-out world has been run once already ({lock}); see docs/heldout.md")
    cfg = load(ROOT / "config" / ("world.json" if args.world == "dev" else "heldout.json"))
    if args.apps:
        cfg["apps"] = args.apps
    rep, sandbox = run(args.world, cfg)
    (reports / f"{args.world}.json").write_text(json.dumps(rep, indent=2) + "\n")
    if args.world == "dev" and not args.apps:
        var = ROOT / "var"
        var.mkdir(exist_ok=True)
        (var / "sandbox.json").write_text(json.dumps(sandbox))
        (var / "past_projects.json").write_text(json.dumps(estimates.past_projects(cfg["seed"], cfg["estimates"]["past_projects"])))
    if args.world == "heldout":
        lock.write_text(f"run once at {datetime.now(timezone.utc).isoformat()}; do not delete\n")
    print(f"{args.world}: {rep['apps']} apps, {rep['advisories']} advisories, gate pass={rep['gate']['pass']} ({rep['took_seconds']}s)")
    for c in rep["gate"]["checks"]:
        print("  " + c)


if __name__ == "__main__":
    main()
