#!/usr/bin/env bash
set -euo pipefail

# Run with Git Bash on Windows. Pin the Laragon PHP used by this project.
PHP_BIN="/c/laragon/bin/php/php8.2.21/php.exe"
PROJECT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

if [[ ! -x "$PHP_BIN" ]]; then
    printf 'Laragon PHP not found: %s\n' "$PHP_BIN" >&2
    exit 1
fi

cd -- "$PROJECT_DIR"
export PATH="${PHP_BIN%/*}:$PATH"

CONCURRENTLY_BIN="$PROJECT_DIR/node_modules/.bin/concurrently"
if [[ ! -f "$CONCURRENTLY_BIN" ]] || ! command -v node >/dev/null 2>&1; then
    printf 'Node.js and npm dependencies are required. Install Node.js and run npm ci first.\n' >&2
    exit 1
fi

printf 'Using PHP: %s\n' "$PHP_BIN"
printf 'Starting DeployHub at http://127.0.0.1:8018\n'
printf 'Starting the database queue worker. Press Ctrl+C to stop both processes.\n'
exec "$CONCURRENTLY_BIN" --kill-others --names=server,queue \
    "php artisan serve --host=127.0.0.1 --port=8018" \
    "php artisan queue:work database --queue=default --sleep=2 --tries=1 --timeout=960"
