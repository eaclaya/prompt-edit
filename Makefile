# One command surface for the Docker stack (docs/PLAN.md §0).
# On the host, commands run inside the `app` container. Inside a container
# (LARAVEL_SAIL=1, e.g. `docker compose run --rm app make ci` in CI) they run directly.

ifdef LARAVEL_SAIL
RUN :=
else
RUN := docker compose exec -u sail app
endif

.PHONY: up down build test lint fix shell tunnel fresh install ci

up: ## Start the stack (app, queue, scheduler, vite, pgsql, redis, mailpit)
	docker compose up -d

down: ## Stop the stack
	docker compose down

build: ## (Re)build the app image
	docker compose build app

install: ## Install PHP and Node dependencies inside the container
	$(RUN) composer install --no-interaction --prefer-dist
	$(RUN) npm ci

test: ## Pest, against Postgres (testing DB) and Redis
	$(RUN) php artisan config:clear --ansi
	$(RUN) php artisan test $(ARGS)

lint: ## Pint (check), Larastan, frontend lint and type-check
	$(RUN) vendor/bin/pint --test
	$(RUN) vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	$(RUN) npx vp check resources compose.yaml
	$(RUN) npm run types:check

fix: ## Apply Pint and frontend formatting fixes
	$(RUN) vendor/bin/pint
	$(RUN) npx vp check --fix resources compose.yaml

shell: ## A shell in the app container
	docker compose exec -u sail app bash

tunnel: ## Start the Cloudflare tunnel (https://prompt-edit.hondukash.dev → app:80)
	docker compose --profile tunnel up -d tunnel

fresh: ## Rebuild the dev database with seed data
	$(RUN) php artisan migrate:fresh --seed

ci: ## Everything CI runs; meant to be run inside the app container
	[ -f .env ] || cp .env.example .env
	composer install --no-interaction --prefer-dist --no-progress
	grep -q '^APP_KEY=base64' .env || php artisan key:generate --ansi
	npm ci --no-audit --no-fund
	npm run build
	$(MAKE) lint
	$(MAKE) test
