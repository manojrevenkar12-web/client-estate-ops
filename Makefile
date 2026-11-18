PY ?= python3
PHPUNIT ?= estate/vendor/bin/phpunit

.PHONY: check php lab estimator web lint study readme heldout sandbox serve

check: php lab estimator lint readme
	git diff --exit-code README.md docs/dashboard.svg

php:
	cd estate && composer install --no-interaction --no-progress --quiet
	cd estate && vendor/bin/phpunit && vendor/bin/phpcs && vendor/bin/phpstan analyse --no-progress

lab:
	cd lab && $(PY) -m pytest -q

estimator:
	cd estimator && $(PY) -m pytest -q

web:
	cd web && npm ci && npm test && npm run build

lint:
	$(PY) ci-templates/lint.py ci-templates/gitlab/*.yml
	terraform fmt -check -recursive infra/terraform

study:
	cd lab && $(PY) -m estate_lab.study --world dev

heldout:
	cd lab && $(PY) -m estate_lab.study --world heldout

readme:
	cd lab && $(PY) -m estate_lab.readme

sandbox:
	php estate/bin/estate import var/sandbox.json --dsn=sqlite:var/estate.sqlite

serve: sandbox
	ESTATE_AS_OF=2026-07-06T00:00:00Z ESTATE_DSN=sqlite:var/estate.sqlite php -S 0.0.0.0:8080 -t estate/public & \
	ESTIMATOR_HISTORY=var/past_projects.json $(PY) -m uvicorn estimator.service:app --app-dir estimator --port 8090
