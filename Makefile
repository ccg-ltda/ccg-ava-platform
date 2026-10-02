COMPOSE      ?= docker compose
COMPOSE_PROD ?= docker compose -f docker-compose.prod.yml

.PHONY: help up down restart build logs ps shell artisan migrate fresh seed test pint \
        e2e-setup e2e-cleanup prod-up prod-down prod-logs prod-migrate

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-14s %s\n", $$1, $$2}'

up: ## Start the development stack (builds the image if needed)
	$(COMPOSE) up -d --build

down: ## Stop the development stack (volumes are kept)
	$(COMPOSE) down

restart: ## Restart the development stack
	$(COMPOSE) restart

build: ## Build the images without starting
	$(COMPOSE) build

logs: ## Follow logs (make logs s=app to filter one service)
	$(COMPOSE) logs -f --tail=100 $(s)

ps: ## Show containers and their health
	$(COMPOSE) ps

shell: ## Shell inside the app container
	$(COMPOSE) exec app sh

artisan: ## Run artisan (make artisan c="route:list")
	$(COMPOSE) exec app php artisan $(c)

migrate: ## Run migrations
	$(COMPOSE) exec app php artisan migrate --force

fresh: ## Drop everything and migrate + seed (DEVELOPMENT ONLY)
	$(COMPOSE) exec app php artisan migrate:fresh --seed --force

seed: ## Run the seeders
	$(COMPOSE) exec app php artisan db:seed --force

# The containers carry the dev environment (pgsql/redis). Tests must never touch it: RefreshDatabase would wipe
# the dev database. Real environment variables beat phpunit.xml, so the testing values are forced here.
TEST_ENV = -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array \
           -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e BROADCAST_CONNECTION=null

test: ## Run the test suite (isolated: sqlite in memory, never the dev database)
	$(COMPOSE) exec $(TEST_ENV) app php artisan test

pint: ## Check code style
	$(COMPOSE) exec app vendor/bin/pint --test

e2e-setup: ## Create isolated browser-test fixtures (E2E_WS + *@e2e.ccg.test users) in the dev database
	$(COMPOSE) exec app php artisan e2e:setup --users=$(or $(users),3) --json

e2e-cleanup: ## Remove ONLY the e2e-tagged records (run it after every browser validation, even a failed one)
	$(COMPOSE) exec app php artisan e2e:cleanup

prod-up: ## Build and start the production stack
	$(COMPOSE_PROD) up -d --build

prod-down: ## Stop the production stack
	$(COMPOSE_PROD) down

prod-logs: ## Follow production logs
	$(COMPOSE_PROD) logs -f --tail=100 $(s)

prod-migrate: ## Run migrations in production (explicit, never automatic)
	$(COMPOSE_PROD) exec app php artisan migrate --force
