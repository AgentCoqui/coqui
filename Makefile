###############################################################################
# Coqui — Development Makefile
#
# Self-documenting: run `make` or `make help` to see all targets.
#
# Naming convention:
#   bare names   — native (no Docker)
#   docker-*     — Docker compose operations
#
# Port defaults: API=3300
###############################################################################

.PHONY: help \
        start stop status repl api api-stop restart \
        dev \
        test-launcher \
        docker-build docker-start docker-stop docker-status \
        docker-repl docker-api docker-api-stop docker-api-logs \
        docker-shell \
        install clean clean-workspace clean-pids \
        composer \
        build build-clean

# Default target
help: ## Show this help message
	@echo ""
	@echo "  Coqui — Development Environment"
	@echo ""
	@echo "Usage: make [target]"
	@echo ""
	@echo "  Native:"
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / && !/^docker-/ {printf "    %-22s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@echo ""
	@echo "  Docker:"
	@awk 'BEGIN {FS = ":.*?## "} /^docker-[a-zA-Z_-]+:.*?## / {printf "    %-22s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@echo ""

# =============================================================================
# Native
# =============================================================================

start: ## Start REPL + API (default)
	@./bin/coqui-launcher $(ARGS)

stop: ## Stop all running services
	@./bin/coqui-launcher stop

status: ## Show running service status
	@./bin/coqui-launcher status

repl: ## Start REPL only (no background API)
	@./bin/coqui-launcher --repl-only $(ARGS)

api: ## Start API only (foreground, port 3300)
ifdef HOST
ifdef PORT
	@./bin/coqui-launcher --api-only --host $(HOST) --port $(PORT) $(ARGS)
else
	@./bin/coqui-launcher --api-only --host $(HOST) $(ARGS)
endif
else ifdef PORT
	@./bin/coqui-launcher --api-only --port $(PORT) $(ARGS)
else
	@./bin/coqui-launcher --api-only $(ARGS)
endif

api-stop: ## Stop the API server
	@./bin/coqui-launcher stop-api

restart: ## Restart REPL + API (clean stop then start)
	@./bin/coqui-launcher stop 2>/dev/null || true
	@./bin/coqui-launcher $(ARGS)

wizard: ## Run the setup wizard (no REPL, no session)
	@./bin/coqui --wizard $(ARGS)

dev: ## Start REPL + API in dev mode
	@./bin/coqui-launcher $(ARGS)

test-launcher: ## Run bash unit tests for the launcher script
	@bash tests/bash/launcher-sigint-test.sh

# =============================================================================
# Docker
# =============================================================================

COMPOSE_API := -f compose.yaml -f compose.api.yaml

docker-build: ## Build the Coqui Docker image
	@docker compose build
	@echo "Image built"

docker-start: ## Start REPL (interactive) + API (background)
	@docker compose $(COMPOSE_API) up -d coqui-api
	@echo "API running at http://localhost:$${COQUI_API_PORT:-3300}"
	@docker compose run --rm coqui $(ARGS)

docker-stop: ## Stop all Docker services
	@docker compose $(COMPOSE_API) \
		down --remove-orphans 2>/dev/null || true
	@echo "All services stopped"

docker-status: ## Show Docker container status
	@docker compose $(COMPOSE_API) ps 2>/dev/null || true

docker-repl: ## Start REPL only (Docker)
	@docker compose run --rm coqui $(ARGS)

docker-api: ## Start API only (daemon, port 3300)
ifdef PORT
	@COQUI_API_PORT=$(PORT) docker compose $(COMPOSE_API) up -d coqui-api
	@echo "API running at http://localhost:$(PORT)"
else
	@docker compose $(COMPOSE_API) up -d coqui-api
	@echo "API running at http://localhost:$${COQUI_API_PORT:-3300}"
endif

docker-api-stop: ## Stop the API container
	@docker compose $(COMPOSE_API) stop coqui-api
	@echo "API stopped"

docker-api-logs: ## Follow API server logs
	@docker compose $(COMPOSE_API) logs -f coqui-api

docker-shell: ## Open a bash shell in the container
	@docker compose run --rm --entrypoint /bin/bash coqui

# =============================================================================
# Build & Release
# =============================================================================

build: ## Build production release (ZIP + tar.gz)
	@scripts/build.sh $(if $(VERSION),--version $(VERSION))

build-clean: ## Remove build artifacts
	@rm -rf BUILD/
	@echo "Build artifacts removed"

# =============================================================================
# Composer
# =============================================================================

install: ## Run composer install (Docker)
	@docker compose run --rm --entrypoint composer coqui install

composer: ## Run composer command (make composer CMD="require foo/bar")
	@if [ -z "$(CMD)" ]; then \
		echo "Usage: make composer CMD=\"require foo/bar\""; \
		exit 1; \
	fi
	@docker compose run --rm --entrypoint composer coqui $(CMD)

# =============================================================================
# Cleanup
# =============================================================================

clean: ## Remove all Docker containers, images, and volumes
	@docker compose $(COMPOSE_API) \
		down -v --remove-orphans --rmi local 2>/dev/null || true
	@echo "Cleaned up"

clean-workspace: ## Remove only the workspace volume
	@docker volume rm coqui_workspace 2>/dev/null || true
	@echo "Workspace volume removed"

clean-pids: ## Remove PID files and kill orphaned processes on known ports
	@rm -f .workspace/pids/*.pid 2>/dev/null || true
	@rm -f /tmp/coqui-pids-$$(id -u)/*.pid 2>/dev/null || true
	@for port in 3300; do \
		pids=$$(lsof -ti tcp:$$port 2>/dev/null || true); \
		if [ -n "$$pids" ]; then \
			echo "Killing process(es) on port $$port: $$pids"; \
			echo "$$pids" | xargs kill 2>/dev/null || true; \
		fi; \
	done
	@echo "PID files cleaned"
