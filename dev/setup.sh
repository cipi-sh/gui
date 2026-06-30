#!/usr/bin/env bash
# Local development host for cipi/gui (macOS/Linux).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${ROOT}/dev/host"
PKG="${ROOT}"

echo "==> Cipi GUI — local dev setup"
echo "    Package: ${PKG}"
echo "    Host:    ${HOST}"

if ! command -v composer >/dev/null 2>&1; then
    echo "Error: composer not found." >&2
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    echo "Error: php not found." >&2
    exit 1
fi

if [[ ! -f "${HOST}/artisan" ]]; then
    echo "==> Creating Laravel project..."
    rm -rf "${HOST}"
    composer create-project laravel/laravel "${HOST}" --no-interaction --prefer-dist
fi

echo "==> Linking cipi/gui from path repository..."
(cd "${HOST}" && composer config repositories.cipi-gui path "${PKG}")
(cd "${HOST}" && composer require cipi/gui:@dev --no-interaction)

echo "==> Configuring .env..."
ENV="${HOST}/.env"
if grep -q '^APP_ENV=' "${ENV}"; then
    sed -i.bak 's|^APP_ENV=.*|APP_ENV=local|' "${ENV}"
else
    echo 'APP_ENV=local' >> "${ENV}"
fi
if grep -q '^APP_DEBUG=' "${ENV}"; then
    sed -i.bak 's|^APP_DEBUG=.*|APP_DEBUG=true|' "${ENV}"
fi
if grep -q '^SESSION_DRIVER=' "${ENV}"; then
    sed -i.bak 's|^SESSION_DRIVER=.*|SESSION_DRIVER=file|' "${ENV}"
else
    echo 'SESSION_DRIVER=file' >> "${ENV}"
fi
rm -f "${ENV}.bak"

# Package registers routes; default welcome would conflict on GET /
echo '<?php' > "${HOST}/routes/web.php"

echo "==> Publishing config & migrating..."
(cd "${HOST}" && php artisan vendor:publish --tag=cipi-gui-config --force)
(cd "${HOST}" && php artisan migrate --force)

echo "==> Seeding admin user..."
(cd "${HOST}" && php artisan cipi:seed-gui-user --password=admin)

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " Dev server ready."
echo ""
echo "   cd dev/host && php artisan serve"
echo "   Login: http://127.0.0.1:8000/login"
echo "   Email: admin@cipi.local  Password: admin"
echo ""
echo " Add a Cipi server under Servers (requires cipi api on target)."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
