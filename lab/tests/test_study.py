import json
import subprocess
import sys

from estate_lab import cost, estimates
from estate_lab.designs import Run
from estate_lab.product import Product
from estate_lab.world import ROOT, World, load


def world(apps=10):
    cfg = load(ROOT / "config" / "world.json")
    cfg["apps"] = apps
    cfg["days"] = 90
    return cfg, World(cfg)


def play(w, design):
    p = None if design == "today" else Product(design)
    r = Run(w, design, p).play()
    if p:
        p.close()
    return r.summary(len(w.apps), 90)


def test_designs_order_as_designed():
    cfg, w = world()
    today, auto, gated = play(w, "today"), play(w, "auto_merge"), play(w, "gated")
    assert gated["exposure"]["critical"]["app_days"] <= today["exposure"]["critical"]["app_days"]
    assert gated["exposure"]["high"]["app_days"] < today["exposure"]["high"]["app_days"]
    assert gated["broken_deploys"] < auto["broken_deploys"], "the canary and the release age stop most breaks"
    assert gated["noise_pages"] < today["noise_pages"], "one-region blips page nobody"
    assert auto["deploys_in_freeze"] > gated["deploys_in_freeze"]


def test_cost_guards_and_estimates():
    cfg, w = world(6)
    rs = cost.resources(w)
    with Product("gated") as p:
        guarded = cost.score("g", rs, p.ask({"op": "audit", "resources": cost.public(rs)})["findings"])
    with Product("naive_cost") as p:
        naive = cost.score("n", rs, p.ask({"op": "audit", "resources": cost.public(rs)})["findings"])
    assert guarded["wrong_actions"] == 0 and naive["wrong_actions"] > 0
    assert guarded["monthly_saving_eur"] > 0
    cfg["estimates"]["briefs"] = 30
    scored = {s["method"]: s for s in estimates.score(estimates.study(cfg, w.seed), cfg)}
    agent = scored["agent with reference class and checks"]
    assert agent["overrun_share"] < scored["person alone (today)"]["overrun_share"]


def test_heldout_runs_once(tmp_path, monkeypatch):
    lock = ROOT / "reports" / "heldout.lock"
    if not lock.exists():
        return  # before the held-out run there is nothing to refuse
    out = subprocess.run([sys.executable, "-m", "estate_lab.study", "--world", "heldout"], cwd=ROOT / "lab",
                         capture_output=True, text=True)
    assert out.returncode != 0 and "run once" in (out.stderr + out.stdout)
    json.loads((ROOT / "reports" / "heldout.json").read_text())
