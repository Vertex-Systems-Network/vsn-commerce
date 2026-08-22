.PHONY: runtime-up runtime-down runtime-build runtime-verify launch-gate runtime-logs android-api-smoke
runtime-build:
	docker compose build app worker scheduler
runtime-up:
	docker compose up -d postgres redis app worker scheduler
runtime-down:
	docker compose down -v --remove-orphans
runtime-logs:
	docker compose logs -f app worker scheduler
runtime-verify:
	bash scripts/runtime-verify.sh
launch-gate:
	docker compose exec app php artisan vsn:launch-gate
android-api-smoke:
	bash scripts/android-api-smoke.sh
