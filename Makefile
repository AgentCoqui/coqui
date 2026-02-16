###############################################################################
# Coqui — Docker Management Makefile
#
# Self-documenting: run `make` or `make help` to see all targets.
###############################################################################

.PHONY: help build run run-launcher dev dev-up dev-down \
        test test-coverage test-shell shell \
        clean xdebug-clear install composer

# Default target
help: ## Show this help message
	@echo ""
	@echo "  Coqui — Docker Development Environment"
	@echo ""
	@echo "Usage: make [target]"
	@echo ""
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-18s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@echo ""

# =============================================================================
# Build
# =============================================================================

build: ## Build the Coqui Docker image
	@docker compose build
	@echo "Image built"

# =============================================================================
# Run
# =============================================================================

run: ## Start interactive REPL
	@docker compose run --rm coqui

run-args: ## Start REPL with args (make run-args ARGS="--auto-approve")
	@docker compose run --rm coqui $(ARGS)

run-launcher: ## Start REPL with crash recovery (coqui-launcher)
	@docker compose run --rm --entrypoint ./bin/coqui-launcher coqui

run-config: ## Start REPL with a specific config file (make run-config CONFIG=openclaw.json)
	@if [ -z "$(CONFIG)" ]; then \
		echo "Usage: make run-config CONFIG=openclaw.json"; \
		exit 1; \
	fi
	@docker compose run --rm -v ./$(CONFIG):/app/openclaw.json:ro coqui

# =============================================================================
# Development
# =============================================================================

dev: ## Start REPL with Xdebug + path repos mounted
	@docker compose -f compose.yaml -f compose.dev.yaml run --rm coqui

dev-up: ## Start dev background services (Webgrind)
	@docker compose -f compose.yaml -f compose.dev.yaml up -d webgrind
	@echo "Webgrind: http://localhost:$${COQUI_WEBGRIND_PORT:-9002}"

dev-down: ## Stop dev background services
	@docker compose -f compose.yaml -f compose.dev.yaml down webgrind
	@echo "Dev services stopped"

# =============================================================================
# Testing
# =============================================================================

test: ## Run Pest tests in Docker
	@docker compose -f compose.yaml -f compose.test.yaml run --rm coqui
	@echo "Tests complete"

test-coverage: ## Run tests with code coverage
	@docker compose -f compose.yaml -f compose.test.yaml run --rm coqui \
		vendor/bin/pest --coverage --min=0
	@echo "Coverage report complete"

test-shell: ## Open interactive shell in test container
	@docker compose -f compose.yaml -f compose.test.yaml run --rm \
		--entrypoint /bin/bash coqui

# =============================================================================
# Utilities
# =============================================================================

shell: ## Open a bash shell in the container
	@docker compose run --rm --entrypoint /bin/bash coqui

install: ## Run composer install inside the container
	@docker compose run --rm --entrypoint composer coqui install

composer: ## Run composer command (make composer CMD="require foo/bar")
	@if [ -z "$(CMD)" ]; then \
		echo "Usage: make composer CMD=\"require foo/bar\""; \
		exit 1; \
	fi
	@docker compose run --rm --entrypoint composer coqui $(CMD)

xdebug-clear: ## Clear Xdebug profiler output files
	@docker compose run --rm --entrypoint clear-xdebug coqui
	@echo "Xdebug files cleared"

# =============================================================================
# Cleanup
# =============================================================================

clean: ## Remove all containers, images, and volumes (destructive!)
	@docker compose -f compose.yaml -f compose.dev.yaml -f compose.test.yaml \
		down -v --remove-orphans --rmi local
	@echo "Cleaned up"

clean-workspace: ## Remove only the workspace volume (resets sessions/data)
	@docker volume rm coqui_workspace 2>/dev/null || true
	@echo "Workspace volume removed"

# =============================================================================
# Shortcuts
# =============================================================================

up: dev-up      ## Alias for dev-up
down: dev-down  ## Alias for dev-down
