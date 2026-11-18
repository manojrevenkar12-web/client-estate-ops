"""Prices features from the agency's own delivery history: a log-linear reference-class model fitted on past
projects (features and the hours they really took), with a P50 and a P80 from its own residuals, and the most
similar past projects as evidence."""

from __future__ import annotations

import math
from dataclasses import dataclass

from .reader import Features


def vector(f: Features) -> list[float]:
    pages = f.pages if f.pages is not None else 12
    return [1.0, math.log1p(pages), float(len(f.integrations)), float(f.auth), float(f.payments), float(f.cms),
            math.log(max(1, f.languages)), float(f.app), float(f.migration), float(f.search)]


NAMES = ["base", "log pages", "integrations", "accounts", "payments", "cms", "log languages", "mobile app",
         "migration", "search"]


@dataclass
class Estimate:
    p50_hours: float
    p80_hours: float
    drivers: dict[str, float]
    similar: list[dict]


class ReferenceClass:
    def __init__(self, past: list[dict], ridge: float = 1.0):
        """past: [{id, name, features: Features-like dict, hours}]"""
        self.past = past
        X = [vector(Features(**p["features"])) for p in past]
        y = [math.log(p["hours"]) for p in past]
        self.beta = _ridge(X, y, ridge)
        res = sorted(yi - _dot(self.beta, xi) for xi, yi in zip(X, y))
        self.q50 = res[len(res) // 2]
        self.q80 = res[int(0.8 * (len(res) - 1))]

    def estimate(self, f: Features) -> Estimate:
        x = vector(f)
        mu = _dot(self.beta, x)
        drivers = {n: round(b * xi, 3) for n, b, xi in zip(NAMES, self.beta, x) if n != "base" and xi}
        dist = []
        for p in self.past:
            xp = vector(Features(**p["features"]))
            dist.append((sum((a - b) ** 2 for a, b in zip(x[1:], xp[1:])), p))
        dist.sort(key=lambda d: d[0])
        similar = [{"id": p["id"], "name": p["name"], "hours": p["hours"]} for _, p in dist[:3]]
        return Estimate(round(math.exp(mu + self.q50), 1), round(math.exp(mu + self.q80), 1), drivers, similar)


def naive_hours(f: Features) -> float:
    """What a model asked for "an estimate" alone tends to produce: a per-feature table, no history, no spread."""
    pages = f.pages if f.pages is not None else 8
    return float(pages * 10 + 40 * len(f.integrations) + 30 * f.auth + 50 * f.payments + 30 * f.cms
                 + 25 * (f.languages - 1) + 200 * f.app + 60 * f.migration + 30 * f.search + 40)


def _dot(a: list[float], b: list[float]) -> float:
    return sum(x * y for x, y in zip(a, b))


def _ridge(X: list[list[float]], y: list[float], lam: float) -> list[float]:
    n = len(X[0])
    A = [[sum(r[i] * r[j] for r in X) + (lam if i == j and i > 0 else 0.0) for j in range(n)] for i in range(n)]
    b = [sum(r[i] * yi for r, yi in zip(X, y)) for i in range(n)]
    # Gaussian elimination with partial pivoting.
    for c in range(n):
        p = max(range(c, n), key=lambda r: abs(A[r][c]))
        A[c], A[p] = A[p], A[c]
        b[c], b[p] = b[p], b[c]
        for r in range(c + 1, n):
            k = A[r][c] / A[c][c]
            for j in range(c, n):
                A[r][j] -= k * A[c][j]
            b[r] -= k * b[c]
    beta = [0.0] * n
    for i in reversed(range(n)):
        beta[i] = (b[i] - sum(A[i][j] * beta[j] for j in range(i + 1, n))) / A[i][i]
    return beta
