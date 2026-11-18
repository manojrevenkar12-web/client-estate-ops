from datetime import timedelta

from estate_lab.world import ROOT, World, load, vkey


def small(apps=8):
    cfg = load(ROOT / "config" / "world.json")
    cfg["apps"] = apps
    return cfg


def test_keyed_luck_does_not_depend_on_estate_size():
    a, b = World(small(6)), World(small(10))
    for x, y in zip(a.apps, b.apps):
        assert (x.id, x.stack, x.deps, x.coverage) == (y.id, y.stack, y.deps, y.coverage)


def test_same_world_twice_is_identical():
    a, b = World(small()), World(small())
    assert [x.id for x in a.advisories] == [x.id for x in b.advisories]
    assert [(r.version, r.at, r.regression) for p in a.packages.values() for r in p.releases] == \
        [(r.version, r.at, r.regression) for p in b.packages.values() for r in p.releases]


def test_advisory_truth_and_feed():
    w = World(small())
    for adv in w.advisories:
        assert adv.introduced in adv.affected
        if adv.fixed is not None:
            assert adv.fixed not in adv.affected
            assert all(vkey(v) < vkey(adv.fixed) for v in adv.affected)
            before = adv.osv(adv.fixed_at - timedelta(seconds=1)) if adv.fixed_at > adv.published else None
            if before is not None:
                assert all("fixed" not in e for e in before["ranges"][0]["events"]), "a late fix is not announced early"
            assert {"fixed": adv.fixed} in adv.osv(w.end)["ranges"][0]["events"]


def test_releases_are_ordered_and_regressions_get_a_follow_up():
    w = World(small())
    for p in w.packages.values():
        rs = sorted(p.releases, key=lambda r: r.at)
        assert [vkey(r.version) for r in rs] == sorted(vkey(r.version) for r in rs)
        for i, r in enumerate(rs):
            if r.regression and r.at < w.end - timedelta(days=5):
                assert any(x.kind == "refix" for x in rs[i + 1:]), f"{p.name} {r.version} never fixed"


def test_heldout_differs():
    dev, held = load(ROOT / "config" / "world.json"), load(ROOT / "config" / "heldout.json")
    assert dev["seed"] != held["seed"] and held["estimates"]["phrasing"] == "heldout"
    assert held["days"] == dev["days"]
