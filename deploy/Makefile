DOCKER_COMPOSE ?= docker compose
BACKEND        ?= $(DOCKER_COMPOSE) exec backend
CONSOLE         = $(BACKEND) php /app/bin/console

.DEFAULT_GOAL := help

help: ## Show this help
	@grep -E '(^[a-zA-Z_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

##
## Logs
##---------------------------------------------------------------------------

logs: ## Tail all container logs
	$(DOCKER_COMPOSE) logs -f --tail=100

logs-backend: ## Tail backend container logs (nginx + php-fpm + messenger + java)
	$(DOCKER_COMPOSE) logs -f --tail=100 backend

logs-redis: ## Tail Redis logs
	$(DOCKER_COMPOSE) logs -f --tail=100 redis

logs-app: ## Tail Symfony application log (rotated daily)
	$(BACKEND) sh -c 'f=$$(ls -t /app/var/log/prod-*.log 2>/dev/null | head -1); [ -n "$$f" ] && tail -f "$$f" || echo "No prod log files found"'

logs-errors: ## Tail Symfony error log (rotated daily)
	$(BACKEND) sh -c 'f=$$(ls -t /app/var/log/error-*.log 2>/dev/null | head -1); [ -n "$$f" ] && tail -f "$$f" || echo "No error log files found"'

logs-messenger: ## Tail messenger worker output (supervisor)
	$(DOCKER_COMPOSE) logs -f --tail=100 backend 2>&1 | grep -i 'messenger'

##
## Messenger & Scheduler
##---------------------------------------------------------------------------

failed-show: ## List failed messages
	$(CONSOLE) messenger:failed:show

failed-count: ## Count failed messages
	$(CONSOLE) messenger:failed:show --max=0 2>&1 | tail -1

failed-retry: ## Retry all failed messages
	$(CONSOLE) messenger:failed:retry --all --force

failed-detail: ## Show details of a failed message: make failed-detail ID=42
	$(CONSOLE) messenger:failed:show $(ID) -vv

failed-remove: ## Remove a failed message: make failed-remove ID=42
	$(CONSOLE) messenger:failed:remove $(ID) --force

scheduler-status: ## Show scheduled tasks and next run times
	$(CONSOLE) debug:scheduler

scheduler-next: ## Show tasks due in the next hour
	$(CONSOLE) debug:scheduler --all

##
## Health & Status
##---------------------------------------------------------------------------

status: ## Show status of all containers
	$(DOCKER_COMPOSE) ps

health: ## Check health of all services
	@echo "=== Containers ==="
	@$(DOCKER_COMPOSE) ps --format "table {{.Name}}\t{{.Status}}"
	@echo ""
	@echo "=== Redis ==="
	@$(DOCKER_COMPOSE) exec redis redis-cli ping
	@echo ""
	@echo "=== Messenger ==="
	@$(CONSOLE) messenger:failed:show --max=0 2>&1 | tail -1
	@echo ""
	@echo "=== Scheduler ==="
	@$(CONSOLE) debug:scheduler 2>&1 | head -20

redis-info: ## Show Redis memory and key stats
	$(DOCKER_COMPOSE) exec redis redis-cli info memory | head -15
	@echo "---"
	$(DOCKER_COMPOSE) exec redis redis-cli info keyspace

redis-monitor: ## Live-stream all Redis commands (Ctrl+C to stop)
	$(DOCKER_COMPOSE) exec redis redis-cli monitor

##
## Debugging
##---------------------------------------------------------------------------

shell: ## Open a shell inside the backend container
	$(BACKEND) /bin/sh

console: ## Run a Symfony console command: make console CMD="debug:router"
	$(CONSOLE) $(CMD)

top: ## Show running processes inside the backend container
	$(BACKEND) top

supervisor-status: ## Show supervisor process status
	$(BACKEND) supervisorctl status

supervisor-restart-messenger: ## Restart messenger workers
	$(BACKEND) supervisorctl restart messenger:*

disk: ## Show disk usage of backend volumes
	$(BACKEND) df -h /app/var /app/storage 2>/dev/null || $(BACKEND) df -h /app/var

##
## Cleanup
##---------------------------------------------------------------------------

clear-cache: ## Clear Symfony cache
	$(CONSOLE) cache:clear --no-warmup
	$(CONSOLE) cache:warmup

clear-logs: ## Remove rotated Symfony log files (keeps last day)
	$(BACKEND) sh -c 'cd /app/var/log && for f in prod-*.log error-*.log; do [ -f "$$f" ] && : > "$$f"; done 2>/dev/null; echo "Logs cleared"'

failed-purge: ## Remove ALL failed messages
	$(CONSOLE) messenger:failed:remove --all --force
