"""Drafts an estimate and a technical concept for a brief. The reader proposes the features; the reference class
prices them; checks decide whether the draft can go to the client or needs answers first. A person approves every
draft: nothing is sent from here."""

from __future__ import annotations

from dataclasses import dataclass

from .model import Estimate, ReferenceClass, naive_hours
from .reader import Features, Rules


@dataclass
class Draft:
    features: Features
    estimate: Estimate | None
    quote_hours: float | None
    status: str  # ready_for_review | needs_answers
    questions: list[str]
    risks: list[str]
    concept: str

    def to_dict(self) -> dict:
        return {"features": self.features.to_dict(), "status": self.status, "quote_hours": self.quote_hours,
                "estimate": None if self.estimate is None else {
                    "p50_hours": self.estimate.p50_hours, "p80_hours": self.estimate.p80_hours,
                    "drivers": self.estimate.drivers, "similar": self.estimate.similar},
                "questions": self.questions, "risks": self.risks, "concept": self.concept}


class Agent:
    def __init__(self, past: list[dict], reader=None, checks: bool = True):
        self.reader = reader or Rules()
        self.model = ReferenceClass(past)
        self.checks = checks

    def draft(self, brief: str) -> Draft:
        f = self.reader.read(brief)
        if not self.checks:  # the ablation: a model's own number, no history, no questions
            h = naive_hours(f)
            return Draft(f, None, h, "ready_for_review", [], [], concept(f))
        est = self.model.estimate(f)
        risks = []
        if f.migration:
            risks.append("migration: effort depends on data quality; price the import after a sample")
        if f.payments and f.integrations:
            risks.append("payments with other integrations: test accounts for every provider needed early")
        if f.app:
            risks.append("mobile app: store review adds one to two weeks to the timeline")
        status = "needs_answers" if f.unknown else "ready_for_review"
        return Draft(f, est, est.p80_hours, status, list(f.unknown), risks, concept(f))


def concept(f: Features) -> str:
    stack = "Next.js front end on a Laravel API" if (f.cms or f.payments) else "Next.js site"
    if f.app:
        stack += ", with a Flutter app on the same API"
    lines = ["# Technical concept (draft for review)", "", f"Stack: {stack}.",
             "Hosting: the agency's Terraform module for AWS (ECS Fargate, RDS) or GCP (Cloud Run, Cloud SQL), "
             "per the client's cloud.", "Delivery: the agency's CI template (tests, dependency checks, canary deploy).", ""]
    parts = [("Accounts and sign-in", f.auth), ("Checkout and payments", f.payments), ("Editorial CMS", f.cms),
             ("Search and filters", f.search), ("Data migration from the old system", f.migration)]
    for name, on in parts:
        if on:
            lines.append(f"- {name}")
    if f.integrations:
        lines.append("- Integrations: " + ", ".join(f.integrations))
    if f.languages > 1:
        lines.append(f"- {f.languages} languages")
    return "\n".join(lines) + "\n"
