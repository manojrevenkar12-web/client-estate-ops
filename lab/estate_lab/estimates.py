"""Client briefs and the hours their projects really took, and how each way of estimating them does.

The world has its own effort model (hidden from the estimator): pages, integrations, accounts, payments, CMS,
languages, an app, a migration and search each add effort, plus a client factor nobody sees in the brief. The
agency's past projects are drawn from the same model; the estimator learns only from those.
"""

from __future__ import annotations

import math
import sys
from pathlib import Path

from .keyed import rng

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "estimator"))
from estimator.agent import Agent  # noqa: E402  (the lab may import the product; never the reverse)

SYSTEMS = ["Salesforce", "HubSpot", "SAP", "Shopify", "Stripe", "Contentful", "Algolia", "Mailchimp", "Personio", "Zendesk"]
CLIENTS = ["a regional brewery", "a bike maker", "a hotel group", "a kitchen manufacturer", "an energy cooperative",
           "a toy shop", "a bakery chain", "a radio station", "a wine merchant", "a fitness chain", "a clinic network"]


def features(r) -> dict:
    return {"pages": r.randint(3, 40), "integrations": r.sample(SYSTEMS, r.choice([0, 0, 1, 1, 2, 3])),
            "auth": r.random() < 0.4, "payments": r.random() < 0.3, "cms": r.random() < 0.6,
            "languages": r.choice([1, 1, 1, 2, 2, 3, 4]), "app": r.random() < 0.12, "migration": r.random() < 0.3,
            "search": r.random() < 0.35}


def true_hours(f: dict, r) -> float:
    x = (3.3 + 0.62 * math.log1p(f["pages"]) + 0.32 * len(f["integrations"]) + 0.25 * f["auth"] + 0.38 * f["payments"]
         + 0.2 * f["cms"] + 0.3 * math.log(f["languages"]) + 0.85 * f["app"] + 0.42 * f["migration"] + 0.15 * f["search"])
    client = 1.35 if r.random() < 0.15 else 1.0  # an indecisive client: more rounds than the brief shows
    return round(math.exp(x + r.gauss(0, 0.22)) * client, 1)


def past_projects(seed: int, n: int) -> list[dict]:
    out = []
    for i in range(n):
        r = rng(seed, "past", i)
        f = features(r)
        reader_view = {**f, "unknown": []}
        out.append({"id": f"P-{2019 + i % 7}-{i:03d}", "name": f"{r.choice(CLIENTS).split(' ', 1)[1].title()} relaunch",
                    "features": reader_view, "hours": true_hours(f, r)})
    return out


def brief(f: dict, r, phrasing: str, omit_pages: bool) -> str:
    client = r.choice(CLIENTS)
    if phrasing == "dev":
        german = r.random() < 0.3
        if german:
            parts = [f"Wir sind {client} und brauchen eine neue Website"]
            if not omit_pages:
                parts.append(f"mit {f['pages']} Seitentypen")
            s = " ".join(parts) + "."
            if f["cms"]:
                s += " Unsere Redaktion pflegt die Inhalte selbst im CMS."
            if f["languages"] > 1:
                s += f" Die Seite soll in {f['languages']} Sprachen erscheinen."
            if f["auth"]:
                s += " Kunden brauchen ein Kundenkonto mit Anmeldung."
            if f["payments"]:
                s += " Es gibt einen Shop mit Bezahlung."
            if f["integrations"]:
                s += " Anbindung an " + ", ".join(f["integrations"]) + "."
            if f["migration"]:
                s += f" Inhalte aus dem Altsystem übernehmen ({r.randint(200, 5000)} Datensätze)."
            if f["search"]:
                s += " Eine Suche mit Filter ist wichtig."
            if f["app"]:
                s += " Dazu eine Mobile App für iOS und Android."
            return s
        s = f"We are {client} and need a new website"
        if not omit_pages:
            s += f" with {f['pages']} page templates"
        s += "."
        if f["cms"]:
            s += " Our editors will manage content in a CMS."
        if f["languages"] > 1:
            s += f" It must be available in {f['languages']} languages."
        if f["auth"]:
            s += " Customers need accounts with login."
        if f["payments"]:
            s += " There is a shop with checkout and payments."
        if f["integrations"]:
            s += " Integrate with " + ", ".join(f["integrations"]) + "."
        if f["migration"]:
            s += f" We need to migrate {r.randint(200, 5000)} records from our old system."
        if f["search"]:
            s += " Search with filters is important."
        if f["app"]:
            s += " We also want a mobile app for iOS and Android."
        return s
    # held-out phrasings: written after the reader was finished
    s = f"Hi! {client.capitalize()} here, planning a relaunch"
    if not omit_pages:
        s += f", roughly {f['pages']} different layouts"
    s += "."
    if f["cms"]:
        s += " Marketing wants to publish news themselves."
    if f["languages"] > 1:
        s += f" Content in {f['languages']} locales."
    if f["auth"]:
        s += " Dealers get a private area behind a password."
    if f["payments"]:
        s += " People should be able to buy online."
    if f["integrations"]:
        s += " It has to talk to our " + " and ".join(f["integrations"]) + "."
    if f["migration"]:
        s += " Everything currently on our TYPO3 needs to come along."
    if f["search"]:
        s += " Visitors must find products fast."
    if f["app"]:
        s += " Ideally also something for phones from the app stores."
    return s


def answers(f: dict, phrasing: str) -> str:
    """What the client replies when the agent asks; written in the same phrasing as their brief."""
    if phrasing == "dev":
        return f" Answers: {f['pages']} page templates. We need to migrate 1500 records." if f["migration"] else \
            f" Answers: {f['pages']} page templates."
    return f" To your questions: around {f['pages']} different layouts."


def study(cfg: dict, seed: int) -> list[dict]:
    e = cfg["estimates"]
    past = past_projects(seed, e["past_projects"])
    checked = Agent(past, checks=True)
    blind = Agent(past, checks=False)
    rows = []
    for i in range(e["briefs"]):
        r = rng(seed, "brief", i)
        f = features(r)
        actual = true_hours(f, rng(seed, "brief-truth", i))
        omit = r.random() < 0.3
        text = brief(f, r, e["phrasing"], omit)
        person = actual * e["person_optimism"] * math.exp(rng(seed, "person", i).gauss(0, e["person_noise"]))
        d = checked.draft(text)
        asked = d.status == "needs_answers"
        if asked:
            d = checked.draft(text + answers(f, e["phrasing"]))
        nb = blind.draft(text)
        rows.append({"actual": actual, "person": round(person, 1), "blind": nb.quote_hours,
                     "agent_p50": d.estimate.p50_hours if d.estimate else None, "agent_quote": d.quote_hours,
                     "asked": asked, "still_open": d.status == "needs_answers",
                     "missed_features": _missed(f, d.features.to_dict())})
    return rows


def _missed(truth: dict, read: dict) -> int:
    n = 0
    for k in ("auth", "payments", "cms", "app", "migration", "search"):
        n += int(bool(truth[k]) != bool(read[k]))
    n += int(set(truth["integrations"]) != set(read["integrations"]))
    n += int(truth["languages"] != read["languages"])
    return n


def score(rows: list[dict], cfg: dict) -> list[dict]:
    rate = cfg["estimates"]["day_rate_eur"] / 8
    out = []
    for name, central, quote, hours_each in (
        ("person alone (today)", "person", "person", 6.0),
        ("agent without checks", "blind", "blind", 1.5),
        ("agent with reference class and checks", "agent_p50", "agent_quote", 1.5),
    ):
        ape = [abs(r[central] - r["actual"]) / r["actual"] for r in rows]
        over = [r for r in rows if r["actual"] > r[quote] * 1.15]
        lost = sum(max(0.0, r["actual"] - r[quote]) * rate for r in rows)
        high = [r for r in rows if r[quote] > r["actual"] * 1.6]
        asked = sum(1 for r in rows if r["asked"]) if quote == "agent_quote" else 0
        out.append({"method": name, "briefs": len(rows), "median_abs_error": round(sorted(ape)[len(ape) // 2], 3),
                    "overrun_share": round(len(over) / len(rows), 3), "margin_lost_eur": round(lost),
                    "overquoted_share": round(len(high) / len(rows), 3), "asked_client": asked,
                    "person_hours": round(len(rows) * hours_each + 0.5 * asked, 1),
                    "features_misread": sum(r["missed_features"] for r in rows) if quote == "agent_quote" else None})
    return out
