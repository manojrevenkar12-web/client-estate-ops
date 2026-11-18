"""Checks the GitLab template without a GitLab instance: every job names a declared stage (directly or through
`extends`), every `extends` target exists, every job has a script. Run in CI."""

import sys
from pathlib import Path

import yaml

RESERVED = {"stages", "variables", "include", "default", "workflow", "image", "services", "before_script", "after_script", "cache"}


def lint(path: Path) -> list[str]:
    doc = yaml.safe_load(path.read_text())
    stages = doc.get("stages", ["build", "test", "deploy"])
    errors = []
    jobs = {k: v for k, v in doc.items() if k not in RESERVED and isinstance(v, dict)}

    def resolved(name: str, key: str):
        job = jobs[name]
        if key in job:
            return job[key]
        parent = job.get("extends")
        for p in [parent] if isinstance(parent, str) else (parent or []):
            if p not in jobs:
                errors.append(f"{name}: extends unknown {p}")
                continue
            v = resolved(p, key)
            if v is not None:
                return v
        return None

    for name in jobs:
        if name.startswith("."):
            continue
        stage = resolved(name, "stage") or "test"
        if stage not in stages:
            errors.append(f"{name}: stage {stage} is not declared")
        if not resolved(name, "script"):
            errors.append(f"{name}: no script")
    return errors


if __name__ == "__main__":
    bad = []
    for f in sys.argv[1:]:
        bad += [f"{f}: {e}" for e in lint(Path(f))]
    print("\n".join(bad) or "ok")
    sys.exit(1 if bad else 0)
