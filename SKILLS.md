# SKILLS.md

Repeatable tasks, with the commands and what done looks like.

## Onboard a delivered client app
1. Add the app to the inventory (`estate import`, or the agency's export in the snapshot shape) with its lock files.
2. Create its infrastructure from `infra/terraform/modules/*` and include `ci-templates/gitlab/estate.gitlab-ci.yml`.
3. Done when `GET /api/apps/{id}` lists its dependencies and exposures and the first canary verdict is recorded.

## Change an update rule
Edit `updates` in `config/estate.json` (lanes, release age, review budget, freezes), then `make study readme`.
Keep a change only if critical exposure, broken-deploy minutes and person-hours do not get worse together.

## Add a design or an ablation
Add an overlay under `designs` in `config/estate.json`, its name to `DESIGNS` in `lab/estate_lab/study.py` and a
line to `ADDS` in `lab/estate_lab/readme.py`, then `make study readme`.

## Add an ecosystem
Add its ordering to `estate/src/Version/` with tests, its name to `Version::compare`, and packages to the world.

## Teach the estimator a feature
Add it to `Features` and `Rules` in `estimator/reader.py`, to `vector` in `model.py`, and to the world's effort
model in `lab/estate_lab/estimates.py`. Done when `make study` shows the agent's overrun share did not rise.

## Run the held-out estate
Once, at the end: `make heldout readme`. Never re-run it for a better number; see docs/heldout.md.
