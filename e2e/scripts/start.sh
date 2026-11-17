#!/usr/bin/env bash
# Boots the e2e stack from scratch: account check, image build, store, seed, tunnel.
# SYLIUS_VERSION (a Composer constraint) and PHP_VERSION pick the versions; defaults: newest.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

export PHP_VERSION="${PHP_VERSION:-8.4}"
export SYLIUS_VERSION="${SYLIUS_VERSION:-^2.3.2}"

# Refuses a live key and an account without card, Bizum, PayPal and Google Pay, before the long build.
node "$E2E_DIR/scripts/preflight.ts"

compose --profile tools down -v --remove-orphans
rm -rf "$GENERATED_DIR"
mkdir -p "$GENERATED_DIR"

# A random kernel secret per run, before php boots. It signs the live component props. Never
# printed, and never unset here: compose would recreate php without it.
(umask 077 && openssl rand -hex 32 >"$GENERATED_DIR/app-secret")
APP_SECRET="$(cat "$GENERATED_DIR/app-secret")"
gha_mask "$APP_SECRET"
export APP_SECRET

compose build php nginx
compose up -d mysql php nginx

# The test env swaps the MONEI HTTP client for a mock, so the store must not run in it.
app_env="$(compose exec -T php printenv APP_ENV)"
[[ "$app_env" =~ ^(prod|dev)$ ]] || fail "the store runs with APP_ENV \"$app_env\"; it must be prod or dev"

console() { compose exec -T php vendor/bin/console --no-interaction "$@"; }
console doctrine:database:create --if-not-exists
console doctrine:schema:create
console sylius:payment:generate-key
# The test application ships its JWT keypair: anyone could sign an admin API token with it.
console lexik:jwt:generate-keypair --overwrite
console sylius:fixtures:load e2e

store_ready() { curl -fsS --max-time 10 -o /dev/null http://localhost:18080/en_US/; }
wait_for 120 nginx "the store on http://localhost:18080" store_ready

# A fresh order ID prefix per run: MONEI's duplicate check on orderId never expires.
E2E_RUN_ID="$(printf '%x%04x' "$(date +%s)" "$RANDOM")"
export E2E_RUN_ID
# A random admin password per run, kept only in a file the specs read. Never printed.
(umask 077 && openssl rand -hex 24 >"$GENERATED_DIR/admin-password")
E2E_ADMIN_PASSWORD="$(cat "$GENERATED_DIR/admin-password")"
gha_mask "$E2E_ADMIN_PASSWORD"
export E2E_ADMIN_PASSWORD
compose run --rm -T seed php /app/seed.php >"$GENERATED_DIR/fixtures.json"
unset E2E_ADMIN_PASSWORD

compose up -d tunnel
registered() { compose logs --no-color tunnel 2>/dev/null | grep -q 'Registered tunnel connection'; }
# Resolving the hostname before cloudflared registers it caches an NXDOMAIN for minutes.
wait_for 90 tunnel "the cloudflared tunnel to register" registered
store_url="$(compose logs --no-color tunnel 2>/dev/null | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1)"
[ -n "$store_url" ] || fail "cloudflared registered a connection but printed no trycloudflare.com URL"
gha_mask "${store_url#https://}"
# Written, never printed: the public repo's CI logs must not show the tunnel host.
printf '%s\n' "$store_url" >"$GENERATED_DIR/store-url"

tunnel_ready() { curl -fsS --max-time 10 -o /dev/null "$store_url/en_US/"; }
wait_for 120 tunnel "the store through the tunnel" tunnel_ready

echo "e2e stack ready: Sylius $SYLIUS_VERSION on PHP $PHP_VERSION; local store http://localhost:18080, tunnel URL in e2e/.generated/store-url"
