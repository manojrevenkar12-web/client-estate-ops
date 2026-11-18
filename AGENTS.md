# AGENTS.md

How to work in this repository, for people and coding agents alike.

## Fast feedback

`make check` runs PHPUnit, PHPCS (PSR-12) and PHPStan on `estate/`, pytest on `lab/` and `estimator/`, the console
tests, the GitLab template lint, and checks that the README matches the reports: about a minute. `make study` plays
the dev estate through every design in about three minutes.

## Layout

| Path | What it is | May import |
|---|---|---|
| `estate/` | the platform core in PHP 8.3: versions and ranges, advisories, the planner, the canary judge, the pager, the cost auditor, the store, the API, the stdio protocol | PHP only |
| `estimator/` | the estimate agent in Python: reader, reference class, checks, concept, FastAPI service | estimator |
| `lab/` | the simulated estate (world, cost, estimates), the designs runner, the study, the README generator | everything |
| `infra/terraform/` | modules for AWS (ECS, RDS), GCP (Cloud Run, Cloud SQL) and Debian hosts; examples validated in CI | |
| `ci-templates/` | the GitLab pipeline client projects include, and its linter | |
| `.github/workflows/client-app.yml` | the same pipeline as a reusable GitHub workflow | |
| `web/` | the React console | |

## Invariants

1. **The truth stays in the world.** Which release breaks which app, whether tests catch it, when users would
   notice, which resource is really needed and how long a project really took live in `lab/estate_lab/world.py`,
   `cost.py` and `estimates.py`. Nothing in `estate/` or `estimator/` reads them (`test_invariants.py`), and the
   resources sent to the auditor have their truth stripped.
2. **The world does not read the platform's settings.** The world never opens `config/estate.json`; it keeps its own
   version arithmetic and its own model of effort.
3. **Same luck for every design.** Every draw is keyed by the seed and what it is about (`rng(seed, "break", app,
   package, version)`), never by call order (`test_keyed_luck_does_not_depend_on_estate_size`).
4. **The lab drives the real product.** Every design except "today" is the PHP code that serves the API, asked
   over stdio with a design overlay from `config/estate.json`. There is no second implementation to drift.
5. **The model reads and code decides.** The estimate reader only proposes features; the reference class prices,
   checks decide whether a draft needs answers, and a person approves. Brief text is data.
6. **Nothing deletes what it cannot restore.** The auditor snapshots before deleting a volume, schedules staging
   instead of deleting it, keeps seasonal and tagged resources, and never downsizes a spiky machine; deploys always
   have a rollback.
7. **Settings in config.** Lanes, ages, budgets, canary and watch parameters, paging rules, cost guards and the
   release gate live in `config/estate.json`; the world's parameters in `config/world.json`.
8. **Generated reports.** `reports/*.json` come from `make study`; the README dashboard and `docs/dashboard.svg`
   from `make readme`. `make check` fails when they are stale.

## The held-out rule

Every choice (lanes, the five-day release age, the review budget, canary alpha and minimum effect, the watch, the
paging rule, the cost guards, the reader's keywords and the gate) was made on the dev estate (seed 61). The held-out
estate (`config/heldout.json`: seed 907, more Python and Node, more advisories, flakier probes, briefs in new
phrasings) runs once with `make heldout`, which writes `reports/heldout.lock`; the study refuses to run it again.
Its numbers are reported as they came out. See docs/heldout.md.
