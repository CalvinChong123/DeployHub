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

printf 'Using PHP: %s\n' "$PHP_BIN"
printf 'Starting DeployHub at http://127.0.0.1:8018\n'
exec "$PHP_BIN" artisan serve --host=127.0.0.1 --port=8018
