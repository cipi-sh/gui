#!/usr/bin/env bash
# Run the panel against the bundled demo API: three fictional Cipi servers with
# Laravel, Node and custom apps, databases, monitor checks, deploy audit, …
#
#   ./dev/demo.sh            # demo API on :8787, panel on :8000
#   ./dev/demo.sh --reset    # also forget every change made in the demo
#
# With Laravel Herd you can serve the demo API on real-looking hostnames:
#   (cd dev/demo/api && herd link cipi-demo && herd secure cipi-demo)
#   CIPI_DEMO_URL='https://{profile}.cipi-demo.test' ./dev/demo.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${ROOT}/dev/host"
API_PORT="${CIPI_DEMO_API_PORT:-8787}"
GUI_PORT="${CIPI_DEMO_GUI_PORT:-8000}"
URL_PATTERN="${CIPI_DEMO_URL:-http://127.0.0.1:${API_PORT}/{profile}}"

if [[ ! -f "${HOST}/artisan" ]]; then
    "${ROOT}/dev/setup.sh"
fi

if [[ "${1:-}" == "--reset" ]]; then
    rm -f "${ROOT}/dev/demo/storage/state.json"
    echo "==> Demo state reset"
fi
mkdir -p "${ROOT}/dev/demo/storage"

echo "==> Registering demo servers (${URL_PATTERN})"
php "${ROOT}/dev/demo/seed.php" "${URL_PATTERN}"

pids=()
cleanup() { for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || true; done; }
trap cleanup EXIT INT TERM

if [[ "${URL_PATTERN}" == http://127.0.0.1:${API_PORT}/* ]]; then
    echo "==> Demo API on http://127.0.0.1:${API_PORT}"
    php -S "127.0.0.1:${API_PORT}" "${ROOT}/dev/demo/api/index.php" >/dev/null 2>&1 &
    pids+=($!)
fi

echo "==> Panel on http://127.0.0.1:${GUI_PORT}  (admin@cipi.local / admin)"
(cd "${HOST}" && php artisan serve --host=127.0.0.1 --port="${GUI_PORT}")
