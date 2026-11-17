# End-to-end suite

Playwright pays against the MONEI test API in a real Sylius store: the Sylius test application
with the refund plugin and this plugin, in `prod` mode, on nginx, php-fpm and MySQL in Docker.
A cloudflared quick tunnel gives the store a public HTTPS host. Playwright browses that host, so
Sylius builds the MONEI callback URL from the real request.

## Requirements

- Docker with Compose, Node.js 22.18 or later, pnpm.
- `e2e/.env` with the test mode API key of a MONEI account that offers card, Bizum and PayPal
  (copy `.env.example`). `MONEI_TEST_ACCOUNT_ID` is optional: when it is set, the run stops if the
  key belongs to another account.

## Run

```sh
pnpm --dir e2e install
pnpm --dir e2e exec playwright install chromium
pnpm --dir e2e e2e:start   # build, boot, seed, open the tunnel
pnpm --dir e2e e2e         # run the specs
pnpm --dir e2e e2e:stop    # remove the stack and close the tunnel
```

`e2e:start` takes `SYLIUS_VERSION` (a Composer constraint, default `^2.3.2`) and `PHP_VERSION`
(default `8.4`), for example `SYLIUS_VERSION=2.2.12 PHP_VERSION=8.2 pnpm --dir e2e e2e:start`.

`e2e:start` refuses a live key, and a key whose account does not offer card, Bizum and PayPal.
Each run seeds its own MONEI `order_id_prefix`, because MONEI's duplicate check on the order ID
never expires. Generated values (store URL, prefix, payment method names, admin password) go to
`e2e/.generated/`.

The local store answers on http://localhost:18080. The admin specs use it there. The tunnel makes
every page public, admin included, and the admin payment method form shows the API key. So the
admin user gets a random password per run, kept only in `e2e/.generated/admin-password`.

## Secrets

- The API key reaches only the one-shot `seed` container. It is never in the image, a log, a
  trace or a screenshot. Scripts never print it.
- No spec opens the admin payment method form, which shows the key. MONEI payment methods are
  created by `scripts/seed.php`.
- The admin password reaches only the `seed` container and the specs. The specs log in with
  Node's `fetch`, never through a Playwright call, and `specs/admin.spec.ts` records no trace,
  screenshot or video.
- Scripts and specs read MONEI with Node's `fetch`, never through the browser.
