#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if command -v fuser >/dev/null 2>&1; then
    fuser -k 8000/tcp >/dev/null 2>&1 || true
fi

# CRITICAL: php -S is single-threaded by default. Without workers an open SSE
# stream (the live AI progress feed holds for up to 5 minutes per connection)
# blocks every other request on the dev server, which felt like "page loads
# take forever". PHP_CLI_SERVER_WORKERS forks N workers so concurrent
# connections don't queue.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"

# Generous limits for local dev so even multi-GB practice videos go through
# without surprise rejections. Production .htaccess + .user.ini set the same
# values for shared hosting (within whatever hard cap the host enforces).
exec php \
    -d upload_max_filesize=5120M \
    -d post_max_size=5200M \
    -d max_execution_time=0 \
    -d max_input_time=0 \
    -d memory_limit=2048M \
    -d file_uploads=On \
    -S 127.0.0.1:8000 \
    -t "$ROOT_DIR/public" \
    "$ROOT_DIR/index.php"
