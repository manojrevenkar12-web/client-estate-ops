"""The product's decisions, asked over JSON lines from the PHP process that also serves the API."""

from __future__ import annotations

import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class Product:
    def __init__(self, design: str = "gated", php: str = "php"):
        self.calls = 0
        self.p = subprocess.Popen([php, str(ROOT / "estate" / "bin" / "estate"), "serve-stdio", f"--design={design}"],
                                  stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True, bufsize=1)

    def ask(self, request: dict) -> dict:
        assert self.p.stdin and self.p.stdout
        self.p.stdin.write(json.dumps(request, separators=(",", ":")) + "\n")
        self.p.stdin.flush()
        line = self.p.stdout.readline()
        if not line:
            raise RuntimeError("the estate process exited")
        self.calls += 1
        out = json.loads(line)
        if "error" in out:
            raise RuntimeError(f"estate: {out['error']} for {request.get('op')}")
        return out

    def close(self) -> None:
        if self.p.stdin:
            self.p.stdin.close()
        self.p.wait(timeout=10)

    def __enter__(self) -> "Product":
        return self

    def __exit__(self, *exc) -> None:
        self.close()
