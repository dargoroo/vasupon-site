.PHONY: dev-up dev-down dev-restart dev-logs dev-ps

dev-up:
	docker compose --env-file .env.docker up -d --build

dev-down:
	docker compose --env-file .env.docker down

dev-restart:
	docker compose --env-file .env.docker down
	docker compose --env-file .env.docker up -d --build

dev-logs:
	docker compose --env-file .env.docker logs -f app db phpmyadmin

dev-ps:
	docker compose --env-file .env.docker ps

dev-reset-db:
	docker compose --env-file .env.docker down -v
	docker compose --env-file .env.docker up -d --build
