"""Keyed luck: every random draw is keyed by the seed and what it is about, never by call order, so every design
sees the same releases, advisories, outages, blips and break outcomes."""

from __future__ import annotations

import hashlib
import random


def rng(seed: int, *parts: object) -> random.Random:
    h = hashlib.blake2b(repr((seed, *parts)).encode(), digest_size=8).digest()
    return random.Random(int.from_bytes(h, "big"))


def uniform(r: random.Random, lo_hi: list[float] | tuple[float, float]) -> float:
    return r.uniform(lo_hi[0], lo_hi[1])


def pick(r: random.Random, weights: dict[str, float]) -> str:
    x = r.random() * sum(weights.values())
    for k, w in weights.items():
        x -= w
        if x <= 0:
            return k
    return next(reversed(weights))
