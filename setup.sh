#!/usr/bin/env bash
# One-command local setup for Seismo (Docker required).
# Usage: ./setup.sh

set -euo pipefail
cd "$(dirname "$0")"

step() {
  echo ""
  echo "==> $*"
}

if ! docker info >/dev/null 2>&1; then
  echo "Docker is not running. Start Docker Desktop (or your Docker daemon), then re-run ./setup.sh" >&2
  exit 1
fi

step "Creating .env (if missing)"
if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Created .env from .env.example"
else
  echo ".env already exists — leaving it unchanged"
fi

UID_GID="$(id -u):$(id -g)"

step "Installing PHP dependencies (Composer via Docker)"
docker run --rm \
  -u "${UID_GID}" \
  -v "$(pwd):/var/www/html" \
  -w /var/www/html \
  laravelsail/php84-composer:latest \
  composer install --no-interaction --prefer-dist --ignore-platform-req=ext-pcntl

step "Starting Sail stack (first run may build the image)"
./vendor/bin/sail up -d

SERVICE="${APP_SERVICE:-laravel.test}"

step "Waiting for app container (${SERVICE})..."
ready=0
for _ in $(seq 1 90); do
  if docker compose exec -T "${SERVICE}" php -v >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done

if [[ "${ready}" -ne 1 ]]; then
  echo "App container did not become ready. Check: ./vendor/bin/sail ps && ./vendor/bin/sail logs" >&2
  exit 1
fi

run_in_app() {
  docker compose exec -T "${SERVICE}" "$@"
}

step "Generating app key (if empty)"
if ! grep -qE '^APP_KEY=base64:' .env; then
  run_in_app php artisan key:generate --force
else
  echo "APP_KEY already set"
fi

step "Running migrations"
run_in_app php artisan migrate --force

step "Installing and building frontend assets"
run_in_app npm install
run_in_app npm run build

echo ""
echo "Seismo is ready."
echo "  App:     http://localhost"
echo "  Horizon: http://localhost/horizon  (local only)"
echo ""
echo "First boot pulls ~30 days of USGS data in the background."
echo "Markers may take a minute or two to appear."
echo ""
echo "Useful commands:"
echo "  ./vendor/bin/sail ps"
echo "  ./vendor/bin/sail logs"
echo "  ./vendor/bin/sail down"
echo ""
