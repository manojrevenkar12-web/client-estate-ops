"""The simulated agency estate: clients, their delivered apps, the packages those apps depend on with every release
the registries publish, security advisories, and the truth that judges every design:

- which release breaks which app, and how (a crash the uptime probes see, or a subtle rise in errors they do not);
- whether a client's test suite would catch it;
- when a person or a user would notice what reached production;
- outages and probe blips that have nothing to do with updates.

The world keeps its own version arithmetic (all of its versions are plain X.Y.Z) and never imports the product or
reads the platform's settings.
"""

from __future__ import annotations

import json
import math
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from pathlib import Path

from .keyed import pick, rng, uniform

ROOT = Path(__file__).resolve().parents[2]
UTC = timezone.utc

ECOSYSTEM = {"laravel": "packagist", "symfony": "packagist", "django": "pypi", "fastapi": "pypi", "nextjs": "npm"}

# Package names per ecosystem: realistic so the console reads like an agency's estate. Releases and advisories
# for them are simulated (advisory ids start with SIM-).
PACKAGES = {
    "packagist": [
        "laravel/framework", "symfony/http-kernel", "symfony/console", "symfony/mailer", "symfony/http-foundation",
        "guzzlehttp/guzzle", "monolog/monolog", "doctrine/orm", "doctrine/dbal", "twig/twig", "league/flysystem",
        "spatie/laravel-permission", "spatie/laravel-medialibrary", "laravel/sanctum", "laravel/horizon",
        "livewire/livewire", "filament/filament", "nesbot/carbon", "phpoffice/phpspreadsheet", "dompdf/dompdf",
        "intervention/image", "predis/predis", "aws/aws-sdk-php", "google/cloud-storage", "stripe/stripe-php",
        "sentry/sentry-laravel", "league/oauth2-client", "firebase/php-jwt", "phpmailer/phpmailer",
        "barryvdh/laravel-debugbar", "maatwebsite/excel", "inertiajs/inertia-laravel", "laravel/socialite",
        "symfony/security-bundle", "api-platform/core", "knplabs/knp-paginator-bundle", "friendsofsymfony/rest-bundle",
        "league/commonmark", "ezyang/htmlpurifier", "mpdf/mpdf",
    ],
    "pypi": [
        "django", "djangorestframework", "celery", "redis", "psycopg", "pillow", "requests", "urllib3", "jinja2",
        "fastapi", "starlette", "pydantic", "uvicorn", "sqlalchemy", "alembic", "gunicorn", "boto3", "google-cloud-storage",
        "stripe", "sentry-sdk", "django-allauth", "django-storages", "wagtail", "django-cors-headers", "httpx",
        "python-multipart", "pyjwt", "cryptography", "lxml", "markdown", "bleach", "openpyxl", "reportlab",
        "django-filter", "whitenoise", "orjson", "python-dotenv", "authlib", "aiohttp", "certifi",
    ],
    "npm": [
        "next", "react", "react-dom", "axios", "express", "lodash", "dayjs", "zod", "next-auth", "@tanstack/react-query",
        "framer-motion", "sharp", "swr", "tailwindcss", "postcss", "@prisma/client", "prisma", "jsonwebtoken", "nodemailer",
        "i18next", "react-hook-form", "@sentry/nextjs", "stripe", "contentful", "@sanity/client", "graphql",
        "@apollo/client", "ws", "socket.io", "multer", "sanitize-html", "marked", "dompurify", "uuid", "qs",
        "cookie", "jose", "body-parser", "ioredis", "pg",
    ],
}

CLIENT_NAMES = [
    "Brauhaus Kessler", "Nordlicht Reisen", "Atelier Morgenrot", "Feldmann Mobility", "Kaffee Krone", "Hafenwerk",
    "Lindner Kitchens", "Sonnwald Energie", "Velo Union", "Pfeffer & Salz", "Rheinblick Hotels", "Alpenmilch",
    "Klangraum Audio", "Stadtgrün", "Wolke Sieben Toys", "Bergfuchs Outdoor", "Mühlbach Bakery", "Taschenlampe Media",
    "Ostsee Living", "Glanz Cosmetics", "Kraftwerk Fitness", "Seidl Wines", "Elbufer Clinics", "Funkturm Radio",
    "Graupner Tools", "Hopfenland", "Isar Studios", "Jadebusen Ferries",
]


def vkey(v: str) -> tuple[int, int, int]:
    a, b, c = (int(x) for x in v.split("."))
    return a, b, c


@dataclass
class Release:
    version: str
    at: datetime
    kind: str  # patch, minor, major, fix (security), refix (regression fixed)
    regression: bool = False
    yanked_at: datetime | None = None


@dataclass
class Package:
    eco: str
    name: str
    releases: list[Release] = field(default_factory=list)

    def published(self, t: datetime) -> list[Release]:
        return [r for r in self.releases if r.at <= t]

    def latest(self, t: datetime) -> Release:
        out = self.published(t)
        return max(out, key=lambda r: vkey(r.version))

    def release(self, version: str) -> Release:
        for r in self.releases:
            if r.version == version:
                return r
        raise KeyError(f"{self.name} {version}")


@dataclass
class Advisory:
    id: str
    eco: str
    package: str
    severity: str
    published: datetime
    affected: set[str]  # the truth: every version the flaw is in
    introduced: str
    fixed: str | None  # the first fixed version, once it exists
    fixed_at: datetime | None

    def osv(self, t: datetime) -> dict:
        events: list[dict[str, str]] = [{"introduced": self.introduced}]
        if self.fixed is not None and self.fixed_at is not None and self.fixed_at <= t:
            events.append({"fixed": self.fixed})
        return {"id": self.id, "ecosystem": self.eco, "package": self.package, "severity": self.severity,
                "published": self.published.isoformat().replace("+00:00", "Z"), "ranges": [{"events": events}]}


@dataclass
class App:
    id: str
    client: str
    name: str
    stack: str
    eco: str
    provider: str
    coverage: float
    base_error: float
    rpm: float
    deps: dict[str, str]  # package -> version at the start
    freezes: list[tuple[datetime, datetime]]

    def frozen(self, t: datetime) -> bool:
        return any(a <= t < b for a, b in self.freezes)


def load(path: str | Path) -> dict:
    """World config; heldout.json is merged onto world.json (maps merge, everything else replaces)."""
    path = Path(path)
    cfg = json.loads((path.parent / "world.json").read_text())
    if path.name != "world.json":
        def merge(a: dict, b: dict) -> dict:
            for k, v in b.items():
                a[k] = merge(a[k], v) if isinstance(v, dict) and isinstance(a.get(k), dict) else v
            return a
        cfg = merge(cfg, json.loads(path.read_text()))
    return cfg


class World:
    def __init__(self, cfg: dict):
        self.cfg = cfg
        self.seed = int(cfg["seed"])
        self.start = datetime.fromisoformat(cfg["start"]).replace(tzinfo=UTC)
        self.end = self.start + timedelta(days=int(cfg["days"]))
        self.packages: dict[str, Package] = {}
        self.apps: list[App] = []
        self.clients: list[dict] = []
        self.advisories: list[Advisory] = []
        self._build()

    # ---- construction ------------------------------------------------------------------------------------------

    def _build(self) -> None:
        cfg = self.cfg
        names = CLIENT_NAMES[: cfg["clients"]]
        self.clients = [{"id": f"c{i:02d}", "name": n} for i, n in enumerate(names)]
        choices: list[tuple[str, list[str]]] = []
        for i in range(cfg["apps"]):
            r = rng(self.seed, "app", i)
            stack = pick(r, cfg["stacks"])
            eco = ECOSYSTEM[stack]
            n = r.randint(*cfg["deps_per_app"])
            pool = PACKAGES[eco]
            # Popular packages are used by most apps: weight by position.
            weights = [1.0 / (1 + j * 0.08) for j in range(len(pool))]
            picked: list[str] = []
            while len(picked) < min(n, len(pool)):
                x = r.random() * sum(weights)
                for j, w in enumerate(weights):
                    x -= w
                    if x <= 0:
                        if pool[j] not in picked:
                            picked.append(pool[j])
                        break
            choices.append((stack, picked))
        usage: dict[tuple[str, str], int] = {}
        for stack, picked in choices:
            for p in picked:
                usage[(ECOSYSTEM[stack], p)] = usage.get((ECOSYSTEM[stack], p), 0) + 1
        planned = self._plan_advisories(usage)
        for (eco, name) in sorted(usage):
            self.packages[f"{eco}:{name}"] = self._timeline(eco, name, [a for a in planned if a["key"] == f"{eco}:{name}"])
        for i, (stack, picked) in enumerate(choices):
            r = rng(self.seed, "app-profile", i)
            p = cfg["apps_profile"]
            client = self.clients[i % len(self.clients)]
            deps = {}
            for name in picked:
                pkg = self.packages[f"{ECOSYSTEM[stack]}:{name}"]
                lag = uniform(rng(self.seed, "lag", i, name), cfg["app_lag_days"])
                deps[name] = pkg.latest(self.start - timedelta(days=lag)).version
            freezes = []
            for k in range(r.randint(*p["freezes"])):
                a = self.start + timedelta(days=r.uniform(0, cfg["days"] - 10))
                freezes.append((a, a + timedelta(days=r.uniform(*p["freeze_days"]))))
            label = {"laravel": "shop", "symfony": "portal", "django": "platform", "fastapi": "api", "nextjs": "site"}[stack]
            same = sum(1 for x in self.apps if x.client == client["id"] and x.id.split("-")[1] == label)
            self.apps.append(App(
                id=f"{client['id']}-{label}-{i:02d}", client=client["id"],
                name=f"{client['name']} {label}" + (f" {same + 1}" if same else ""),
                stack=stack, eco=ECOSYSTEM[stack], provider="aws" if r.random() < cfg["aws_share"] else "gcp",
                coverage=r.uniform(*p["coverage"]), base_error=r.uniform(*p["base_error"]),
                rpm=math.exp(r.uniform(math.log(p["rpm"][0]), math.log(p["rpm"][1]))), deps=deps, freezes=freezes))
        self._finish_advisories(planned)

    def _plan_advisories(self, usage: dict[tuple[str, str], int]) -> list[dict]:
        c = self.cfg["advisories"]
        keys = sorted(usage)
        weights = {f"{e}:{n}": float(usage[(e, n)]) for e, n in keys}
        out = []
        t = self.start - timedelta(days=30)
        i = 0
        while True:
            r = rng(self.seed, "advisory", i)
            t = t + timedelta(days=r.expovariate(c["per_week"] / 7.0))
            if t >= self.end:
                break
            key = pick(r, weights)
            kind = "major" if r.random() < c["major_only_share"] else ("late" if r.random() < c["no_fix_yet_share"] else "fix")
            out.append({"i": i, "key": key, "at": t.replace(microsecond=0), "severity": pick(r, c["severity"]),
                        "kind": kind, "late_days": r.uniform(2, 10), "back": r.randint(*c["affects_back_releases"])})
            i += 1
        return out

    def _timeline(self, eco: str, name: str, advisories: list[dict]) -> Package:
        """Every release from well before the simulation to its end, with regressions, their quick fixes, yanks and
        the security fixes the advisories need."""
        rel = self.cfg["releases"]
        r = rng(self.seed, "package", eco, name)
        major, minor, patch = r.randint(1, 9), r.randint(0, 20), r.randint(0, 9)
        t = self.start - timedelta(days=400)
        pkg = Package(eco, name, [Release(f"{major}.{minor}.{patch}", t, "patch")])
        events: list[tuple[datetime, str, dict | None]] = []
        for kind, every in (("patch", rel["patch_every_days"]), ("minor", rel["minor_every_days"]),
                            ("major", rel["major_every_days"])):
            s = t
            j = 0
            while s < self.end:
                s = s + timedelta(days=rng(self.seed, "rel", eco, name, kind, j).expovariate(1.0 / every))
                events.append((s.replace(microsecond=0), kind, None))
                j += 1
        for a in advisories:
            fix_at = a["at"] + timedelta(days=a["late_days"]) if a["kind"] == "late" else a["at"]
            events.append((fix_at, "fix-major" if a["kind"] == "major" else "fix", a))
        events.sort(key=lambda e: e[0])
        pending: list[tuple[datetime, int]] = []  # regression fixes due
        k = 0
        while events or pending:
            if pending and (not events or pending[0][0] <= events[0][0]):
                at, _ = pending.pop(0)
                kind, adv = "refix", None
            else:
                at, kind, adv = events.pop(0)
            if at >= self.end + timedelta(days=30):
                break
            if kind in ("major", "fix-major"):
                major, minor, patch = major + 1, 0, 0
            elif kind == "minor":
                minor, patch = minor + 1, 0
            else:
                patch += 1
            bump = "major" if kind in ("major", "fix-major") else ("minor" if kind == "minor" else "patch")
            rr = rng(self.seed, "regression", eco, name, k)
            regression = kind in ("patch", "minor", "major") and rr.random() < rel["regression"][bump]
            release = Release(f"{major}.{minor}.{patch}", at, kind, regression)
            pkg.releases.append(release)
            if adv is not None:
                adv["fixed"] = release.version
                adv["fixed_at"] = at
            if regression:
                fix_at = at + timedelta(days=rr.uniform(*rel["regression_fixed_after_days"]))
                if rr.random() < rel["yank_share"]:
                    release.yanked_at = fix_at
                pending.append((fix_at.replace(microsecond=0), k))
                pending.sort()
            k += 1
        return pkg

    def _finish_advisories(self, planned: list[dict]) -> None:
        for a in planned:
            eco, name = a["key"].split(":", 1)
            pkg = self.packages[a["key"]]
            before = [x for x in pkg.releases if x.at < a["at"] and (a.get("fixed") is None or vkey(x.version) < vkey(a["fixed"]))]
            before.sort(key=lambda x: vkey(x.version))
            if not before:
                continue
            intro = before[max(0, len(before) - a["back"])]
            fixed = a.get("fixed")
            affected = {x.version for x in pkg.releases
                        if vkey(x.version) >= vkey(intro.version) and (fixed is None or vkey(x.version) < vkey(fixed))}
            self.advisories.append(Advisory(
                id=f"SIM-{a['at'].year}-{a['i']:04d}", eco=eco, package=name, severity=a["severity"], published=a["at"],
                affected=affected, introduced=intro.version, fixed=fixed, fixed_at=a.get("fixed_at")))

    # ---- what the registries and advisory feeds show at time t ----------------------------------------------------

    def available(self, eco: str, name: str, current: str, t: datetime) -> list[dict]:
        pkg = self.packages[f"{eco}:{name}"]
        out = []
        for r in pkg.published(t):
            if vkey(r.version) > vkey(current):
                out.append({"version": r.version, "released": r.at.isoformat().replace("+00:00", "Z"),
                            "yanked": r.yanked_at is not None and r.yanked_at <= t})
        return out

    def advisories_at(self, t: datetime) -> list[Advisory]:
        return [a for a in self.advisories if a.published <= t]

    # ---- the truth ---------------------------------------------------------------------------------------------

    def breaks(self, app: App, name: str, frm: str, to: str) -> dict | None:
        """Does moving this app from one version to another break it, and how? Keyed by app and target."""
        pkg = self.packages[f"{app.eco}:{name}"]
        rel = pkg.release(to)
        r = rng(self.seed, "break", app.id, name, to)
        b = self.cfg["breaks"]
        major = vkey(frm)[0] != vkey(to)[0] or (vkey(frm)[0] == 0 and vkey(frm)[1] != vkey(to)[1])
        broken = (rel.regression and r.random() < self.cfg["releases"]["app_uses_feature"]) or (
            major and r.random() < self.cfg["releases"]["regression"]["major"] * 1.6)
        if not broken:
            return None
        kind = "crash" if r.random() < b["crash_share"] else "subtle"
        return {"kind": kind, "extra_error": r.uniform(*b["subtle_extra_error"]) if kind == "subtle" else 1.0,
                "report_hours": r.lognormvariate(math.log(b["user_report_hours_median"]), 0.8)}

    def tests_catch(self, app: App, name: str, to: str, kind: str) -> bool:
        p = app.coverage * self.cfg["breaks"]["tests_catch"][kind]
        return rng(self.seed, "ci", app.id, name, to).random() < p

    def probe_events(self, app: App, day: datetime) -> list[dict]:
        """Blips (one region, one round, nothing wrong) and real outages (every region) on one day."""
        p = self.cfg["probes"]
        r = rng(self.seed, "probes", app.id, day.date().isoformat())
        out = []
        for _ in range(_poisson(r, p["blips_per_app_week"] / 7.0)):
            at = day + timedelta(minutes=r.randrange(24 * 60))
            regions = [r.choice(p["regions"])]
            if r.random() < p["two_region_blip_share"]:
                regions = r.sample(p["regions"], 2)
            out.append({"kind": "blip", "at": at, "minutes": 1, "regions": regions})
        if r.random() < p["outages_per_app_month"] / 30.0:
            at = day + timedelta(minutes=r.randrange(24 * 60))
            out.append({"kind": "outage", "at": at, "minutes": int(r.uniform(*p["outage_minutes"])),
                        "regions": list(p["regions"])})
        return sorted(out, key=lambda e: e["at"])


def _poisson(r, lam: float) -> int:
    n, p, limit = 0, 1.0, math.exp(-lam)
    while True:
        p *= r.random()
        if p <= limit:
            return n
        n += 1


def iso(t: datetime) -> str:
    return t.isoformat().replace("+00:00", "Z")
