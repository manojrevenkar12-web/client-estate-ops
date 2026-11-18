"""The invariants in AGENTS.md, checked on the source."""

import re

from estate_lab.world import ROOT


def test_product_never_reads_the_world():
    for path in list((ROOT / "estate").rglob("*.php")) + list((ROOT / "estimator" / "estimator").rglob("*.py")):
        if "vendor" in path.parts:
            continue
        text = path.read_text()
        assert "estate_lab" not in text and "world.json" not in text and "heldout.json" not in text, path


def test_world_never_reads_the_product_settings():
    for name in ("world.py", "cost.py", "keyed.py"):
        text = (ROOT / "lab" / "estate_lab" / name).read_text()
        assert "estate.json" not in text, name
        assert not re.search(r"^from \.(product|designs|study)", text, re.M), name


def test_truth_is_not_sent_to_the_product():
    from estate_lab import cost
    from estate_lab.world import World, load

    cfg = load(ROOT / "config" / "world.json")
    cfg["apps"] = 4
    rs = cost.resources(World(cfg))
    assert all("truth" not in r for r in cost.public(rs))
