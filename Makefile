.PHONY: help setup start stop restart logs clean test lint format

# Default target
help: ## Show this help message
	@echo "Fraud Detection System - Development Commands"
	@echo "============================================="
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# Development Environment
setup: ## Initial project setup
	@echo "Setting up fraud detection system..."
	@mkdir -p data/{models,rules,samples}
	@mkdir -p tests/{unit,integration,data}
	@mkdir -p infrastructure/{terraform,docker,scripts}
	@cp .env.example .env.local || echo "Create .env.local manually"
	@echo "Setup complete! Run 'make start' to begin development."

start: ## Start all services
	@echo "Starting fraud detection services..."
	docker-compose up -d
	@echo "Services started. API available at http://localhost:8080"
	@echo "ML Service available at http://localhost:8000"

stop: ## Stop all services
	@echo "Stopping fraud detection services..."
	docker-compose down

restart: ## Restart all services
	@echo "Restarting fraud detection services..."
	docker-compose restart

logs: ## Show logs from all services
	docker-compose logs -f

logs-api: ## Show API logs
	docker-compose logs -f api

logs-worker: ## Show worker logs
	docker-compose logs -f worker

logs-ml: ## Show ML service logs
	docker-compose logs -f ml-service

# Database Operations
db-migrate: ## Run database migrations
	docker-compose exec api php artisan migrate

db-seed: ## Seed database with test data
	docker-compose exec api php artisan db:seed

db-reset: ## Reset database (migrate fresh + seed)
	docker-compose exec api php artisan migrate:fresh --seed

db-shell: ## Connect to database shell
	docker-compose exec postgres psql -U fraud_user -d fraud_detector_dev

# Testing
test: ## Run all tests
	@echo "Running unit tests..."
	docker-compose exec api php artisan test
	@echo "Running ML service tests..."
	docker-compose exec ml-service pytest tests/
	@echo "Running integration tests..."
	docker-compose --profile testing run --rm test-client python -m pytest

test-api: ## Run API tests only
	docker-compose exec api php artisan test

test-ml: ## Run ML service tests only
	docker-compose exec ml-service pytest tests/ -v

test-integration: ## Run integration tests
	docker-compose --profile testing run --rm test-client python -m pytest tests/integration/

test-load: ## Run load tests
	docker-compose --profile testing run --rm test-client artillery run tests/performance/load_test.yml

# Code Quality
lint: ## Run code linting
	@echo "Linting PHP code..."
	docker-compose exec api ./vendor/bin/phpstan analyse
	@echo "Linting Python code..."
	docker-compose exec ml-service flake8 .

format: ## Format code
	@echo "Formatting PHP code..."
	docker-compose exec api ./vendor/bin/php-cs-fixer fix
	@echo "Formatting Python code..."
	docker-compose exec ml-service black .

# Development Utilities
shell-api: ## Access API container shell
	docker-compose exec api bash

shell-ml: ## Access ML service container shell
	docker-compose exec ml-service bash

shell-db: ## Access database container shell
	docker-compose exec postgres bash

# Queue Management
queue-status: ## Show queue status
	docker-compose exec api php artisan queue:monitor

queue-clear: ## Clear failed jobs
	docker-compose exec api php artisan queue:clear

queue-restart: ## Restart queue workers
	docker-compose restart worker

# Sample Data and Testing
sample-request: ## Submit sample fraud detection request
	@echo "Submitting sample application..."
	curl -X POST http://localhost:8080/applications \
		-H "Content-Type: application/json" \
		-H "X-Api-Key: test-key-123" \
		-H "X-Timestamp: $$(date +%s)" \
		-H "X-Nonce: test-nonce-$$(date +%s)" \
		-H "X-Signature: test-signature" \
		-d @tests/data/sample_application.json

health-check: ## Check health of all services
	@echo "Checking service health..."
	@echo "API Health:"
	@curl -s http://localhost:8080/health || echo "API not responding"
	@echo "\nML Service Health:"
	@curl -s http://localhost:8000/healthz || echo "ML Service not responding"
	@echo "\nDatabase Health:"
	@docker-compose exec postgres pg_isready -U fraud_user -d fraud_detector_dev || echo "Database not ready"

# Model Management
model-download: ## Download latest ML model (mock)
	@echo "Downloading latest model artifacts..."
	@mkdir -p data/models/v1.0.0
	@echo "Mock model downloaded to data/models/v1.0.0/"

model-validate: ## Validate ML model
	docker-compose exec ml-service python scripts/validate_model.py

# Configuration Management
config-validate: ## Validate all configuration files
	@echo "Validating configurations..."
	docker-compose exec api php artisan config:validate || echo "API config validation not implemented"
	docker-compose exec ml-service python scripts/validate_config.py || echo "ML config validation not implemented"

rules-update: ## Update rules configuration
	docker-compose exec api php artisan rules:update data/rules/rules_v1.json

policy-update: ## Update decision policy
	docker-compose exec api php artisan policy:update data/rules/policy_v1.json

# Monitoring and Debugging
metrics: ## Show system metrics
	@echo "System Metrics:"
	@echo "==============="
	@echo "Queue depth:"
	@docker-compose exec api php artisan queue:monitor || echo "Queue monitoring not available"
	@echo "\nContainer stats:"
	@docker stats --no-stream --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}"

debug-pipeline: ## Debug pipeline with sample data
	@echo "Running pipeline debug with sample data..."
	docker-compose exec api php artisan fraud:debug tests/data/sample_application.json

# Cleanup
clean: ## Clean up containers and volumes
	@echo "Cleaning up fraud detection system..."
	docker-compose down -v
	docker system prune -f
	@echo "Cleanup complete."

clean-data: ## Clean up data directories (careful!)
	@echo "WARNING: This will delete all local data!"
	@read -p "Are you sure? [y/N] " -n 1 -r; \
	if [[ $$REPLY =~ ^[Yy]$$ ]]; then \
		rm -rf data/models/* data/samples/* || true; \
		echo "\nData directories cleaned."; \
	else \
		echo "\nCancelled."; \
	fi

# Documentation
docs-serve: ## Serve documentation locally
	@echo "Starting documentation server..."
	@command -v python3 >/dev/null 2>&1 && \
		cd docs && python3 -m http.server 8888 || \
		echo "Python3 not found. Install Python to serve docs."

docs-build: ## Build documentation (if using static site generator)
	@echo "Building documentation..."
	@echo "Documentation is in Markdown format. Use 'make docs-serve' to view locally."

# Production Helpers (for future use)
build-prod: ## Build production images
	@echo "Building production images..."
	docker-compose -f docker-compose.prod.yml build

deploy-staging: ## Deploy to staging environment
	@echo "Deploying to staging..."
	@echo "Staging deployment not implemented yet."

backup-db: ## Backup database
	@echo "Creating database backup..."
	docker-compose exec postgres pg_dump -U fraud_user fraud_detector_dev > backup_$$(date +%Y%m%d_%H%M%S).sql
	@echo "Backup created: backup_$$(date +%Y%m%d_%H%M%S).sql"

# Development workflow shortcuts
dev-reset: stop clean setup start ## Complete development reset
	@echo "Development environment reset complete!"

quick-test: ## Quick smoke test
	@echo "Running quick smoke test..."
	@make health-check
	@make sample-request
	@echo "Quick test complete!"
