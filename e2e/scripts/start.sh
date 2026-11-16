#!/usr/bin/env bash
# Boots the e2e stack from scratch: account check, image build, store, seed, tunnel.
# SYLIUS_VERSION (a Composer constraint) and PHP_VERSION pick the versions; defaults: newest.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

export PHP_VERSION="${PHP_VERSION:-8.4}"
export SYLIUS_VERSION="${SYLIUS_VERSION:-^2.3.2}"
[[ "$PHP_VERSION" =~ ^8\.[0-9]+$ ]] || fail "PHP_VERSION must be 8.x, got \"$PHP_VERSION\""
[[ "$SYLIUS_VERSION" =~ ^[~^]?2\.[0-9]+(\.[0-9]+)?$ ]] || fail "SYLIUS_VERSION must be a 2.x Composer constraint, got \"$SYLIUS_VERSION\""

# Refuses a live key and an account without card, Bizum and PayPal, before the long build.
node "$E2E_DIR/scripts/preflight.ts"

compose --profile tools down -v --remove-orphans
rm -rf "$GENERATED_DIR"
mkdir -p "$GENERATED_DIR"

compose build php nginx
compose up -d mysql php nginx

# The test env swaps the MONEI HTTP client for a mock, so the store must not run in it.
app_env="$(compose exec -T php vendor/bin/console about | sed -n 's/^ *Environment *//p' | tr -d ' ')"
[[ "$app_env" =~ ^(prod|dev)$ ]] || fail "the store runs with APP_ENV \"$app_env\"; it must be prod or dev"

console() { compose exec -T php vendor/bin/console --no-interaction "$@"; }
console doctrine:database:create --if-not-exists
console doctrine:schema:create
console sylius:payment:generate-key
console sylius:fixtures:load e2e

store_ready() { curl -fsS -o /dev/null http://localhost:18080/en_US/; }
wait_for 120 nginx "the store on http://localhost:18080" store_ready

# A fresh order ID prefix per run: MONEI's duplicate check on orderId never expires.
E2E_RUN_ID="$(printf '%x%04x' "$(date +%s)" "$RANDOM")"
export E2E_RUN_ID
compose run --rm -T seed php /app/seed.php >"$GENERATED_DIR/fixtures.json"

compose up -d tunnel
registered() { compose logs --no-color tunnel 2>/dev/null | grep -q 'Registered tunnel connection'; }
# Resolving the hostname before cloudflared registers it caches an NXDOMAIN for minutes.
wait_for 90 tunnel "the cloudflared tunnel to register" registered
store_url="$(compose logs --no-color tunnel 2>/dev/null | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1)"
[ -n "$store_url" ] || fail "cloudflared registered a connection but printed no trycloudflare.com URL"
# Written, never printed: the public repo's CI logs must not show the tunnel host.
printf '%s\n' "$store_url" >"$GENERATED_DIR/store-url"

tunnel_ready() { curl -fsS -o /dev/null "$store_url/en_US/"; }
wait_for 120 tunnel "the store through the tunnel" tunnel_ready

echo "e2e stack ready: Sylius $SYLIUS_VERSION on PHP $PHP_VERSION; local store http://localhost:18080, tunnel URL in e2e/.generated/store-url"
