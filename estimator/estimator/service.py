"""HTTP service: POST /estimates with a brief returns a draft for a person to review."""

from __future__ import annotations

import json
import os
from pathlib import Path

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from .agent import Agent
from .reader import default_reader

HISTORY = Path(os.environ.get("ESTIMATOR_HISTORY", Path(__file__).resolve().parents[2] / "var" / "past_projects.json"))


class Brief(BaseModel):
    brief: str = Field(min_length=20, max_length=20_000)


def create_app(past: list[dict] | None = None) -> FastAPI:
    app = FastAPI(title="estate estimator")
    state: dict = {}

    def agent() -> Agent:
        if "agent" not in state:
            data = past if past is not None else (json.loads(HISTORY.read_text()) if HISTORY.exists() else None)
            if not data:
                raise HTTPException(503, "no delivery history loaded: run `make sandbox` or set ESTIMATOR_HISTORY")
            state["agent"] = Agent(data, reader=default_reader())
        return state["agent"]

    @app.get("/health")
    def health() -> dict:
        return {"ok": True}

    @app.post("/estimates")
    def estimate(b: Brief) -> dict:
        return agent().draft(b.brief).to_dict()

    return app


app = create_app()
