APP=link-creator

.PHONY: help up exec down

help:
	@awk 'function build_alias(cmds) { split(cmds, array, " "); res=array[1]; for (i=2; i<=length(array); i++) { res= res " (alias: " array[i] ")"; } return res; } BEGIN {FS = ":.*##"; printf "Usage: make [TARGET]\nTargets:\n"} /^[a-zA-Z_\- ]+:.*?##/ { printf "  \033[36m%-20s\033[0m %s\n", build_alias($$1),  $$2; } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

up: ## initialize database and rabbitMq
	docker compose up -d --build

ps: ## initialize database and rabbitMq
	docker compose ps

down: ## initialize database and rabbitMq
	docker compose down -v --remove-orphans

exec: ## initialize database and rabbitMq
	docker exec -it link-creator-app bash

lint: ## phpStan
	docker exec -it link-creator-app vendor/bin/phpstan analyse
