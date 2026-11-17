# End-to-end suite

Playwright pays against the MONEI test API in a real Sylius store. The store is the Sylius test
application with the refund plugin and this plugin. It runs in `prod` mode, on nginx, php-fpm and
MySQL in Docker. A cloudflared quick tunnel gives the store a public HTTPS host. Playwright opens
the shop on that host. Sylius then builds the MONEI callback URL from the real request.

## Requirements

- Docker with Compose, Node.js 22.18 or later, pnpm, `openssl` and `curl`.
- `e2e/.env` with the test mode API key of a MONEI account that offers card, Bizum, PayPal and
  Google Pay (copy `.env.example`).

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

`e2e:start` refuses a live key, and a key whose account does not offer card, Bizum, PayPal and
Google Pay. Each run seeds its own MONEI `order_id_prefix`, because MONEI's duplicate check on the
order ID never expires. Generated values (store URL, payment method names, admin password, app
secret) go to `e2e/.generated/`.

The local store answers on http://localhost:18080, bound to 127.0.0.1 only. The admin specs use it
there. On the tunnel host, nginx answers 404 for `/admin`, `/api/v2/admin` and the `sylius_admin:`
live components: the admin payment method form shows the API key. The admin user also gets a
random password per run, kept only in `e2e/.generated/admin-password`. `e2e:start` replaces the
JWT keypair that the test application ships, so nobody can sign an admin API token with it.

## Secrets

- The API key is in `e2e/.env`. The Node scripts and specs read it from there, to call MONEI. The
  one-shot `seed` container gets it and writes it, encrypted, into the gateway config in the
  database. The `php` container reads it from there. It is never in the image, a log, a trace or
  a screenshot. Scripts never print it.
- `e2e:start` sets a random `APP_SECRET` per run, in `e2e/.generated/app-secret` and in the
  environment of the `php` and `seed` containers. The test application's `.env` ships a public
  one, which would let anyone sign live component props. Scripts never print it.
- Scripts never print the tunnel host. In GitHub Actions, `start.sh` masks it in the job log as
  soon as it knows it.
- No spec opens the admin payment method form, which shows the key. MONEI payment methods are
  created by `scripts/seed.php`.
- The admin password reaches only the `seed` container and the specs. The specs log in with
  Node's `fetch`, never through a Playwright call, and `specs/admin.spec.ts` records no trace,
  screenshot or video.
- Scripts and specs read MONEI with Node's `fetch`, never through the browser.
