<p align="center">
    <a href="https://monei.com" target="_blank">
        <img src="https://assets.monei.com/images/logo.svg" width="200" alt="MONEI">
    </a>
</p>

<h1 align="center">MONEI Payment Plugin for Sylius</h1>

<p align="center">
    Accept payments with <a href="https://monei.com">MONEI</a> in your Sylius store: card, Bizum, PayPal, Apple Pay, Google Pay, MB WAY and Multibanco.
    <br>
    MONEI is a licensed Payment Institution (Banco de España #6911).
</p>

<p align="center">
    <a href="https://github.com/MONEI/SyliusMoneiPlugin/actions"><img src="https://github.com/MONEI/SyliusMoneiPlugin/workflows/CI/badge.svg" alt="Build Status"></a>
    <a href="https://packagist.org/packages/monei/sylius-monei-plugin"><img src="https://poser.pugx.org/monei/sylius-monei-plugin/v/stable" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/monei/sylius-monei-plugin"><img src="https://poser.pugx.org/monei/sylius-monei-plugin/license" alt="License"></a>
    <img src="https://img.shields.io/badge/Sylius-2.2.13%2B%20%7C%202.3.3%2B-blue" alt="Sylius Version">
    <img src="https://img.shields.io/badge/PHP-8.2%2B-purple" alt="PHP Version">
</p>

---

Version 2.0.0 is a full rewrite. If you installed 1.x, read [Upgrade from 1.x](#upgrade-from-1x).

## Features

- One Sylius payment method per MONEI method: card, Bizum, PayPal, Apple Pay / Google Pay, MB WAY, Multibanco.
- Two modes per payment method:
  - **Hosted**: the buyer pays on the MONEI payment page.
  - **Onsite**: the buyer pays in your store, with monei.js. Card, Bizum, PayPal and Apple Pay / Google Pay only.
- **Inline payment** (onsite with an Account ID): the card fields or the method button show on the checkout Complete step. See [Inline payment](#inline-payment).
- Method logos next to each MONEI method on the checkout Payment step, and a "Test mode" badge for a `pk_test_` key. On Sylius 2.3, the admin payment method list shows the MONEI logo and the method logos, unless your store sets its own template for the name field of the `sylius_admin_payment_method` grid.
- Authorize now, capture later from the Sylius admin (card, Apple Pay / Google Pay, PayPal).
- Refunds with the core Refund button, or full and partial refunds with [`sylius/refund-plugin`](https://github.com/Sylius/RefundPlugin).
- The payment status always comes from the MONEI API.
- Apple Pay domain registration when you save the payment method.
- 3D Secure (PSD2 / SCA) runs on MONEI.
- Uses the Sylius Payment Requests API. It does not use Payum.
- Admin and shop translations in English and Spanish.

## Requirements

- PHP 8.2 or later.
- Sylius 2.2.13 or later on the 2.2 line, or Sylius 2.3.3 or later.
- The Symfony Workflow state machine adapter (`symfony_workflow`) for the `sylius_payment` and `sylius_order` graphs. See [State machine adapter](#state-machine-adapter).
- A public store URL that MONEI can reach from the internet. MONEI sends a callback for each payment to it. See [Callback URL](#callback-url).
- A MONEI account with the payment methods you want to offer. Get the API key in the [MONEI Dashboard](https://dashboard.monei.com/), under Settings, API Access.

### State machine adapter

The plugin calls MONEI from Symfony Workflow events. With another adapter (winzou), a capture, cancel or refund in the admin would change Sylius without a call to MONEI. The Sylius 2 default is `symfony_workflow`.

- When your configuration maps `sylius_payment` or `sylius_order` to another adapter, the container does not build. The error names the graph.
- When the adapter comes from an environment variable, the plugin cannot check it at build time. Make sure that the variable resolves to `symfony_workflow`.

```yaml
# config/packages/sylius_state_machine_abstraction.yaml (only if you changed the default)
sylius_state_machine_abstraction:
    graphs_to_adapters_mapping:
        sylius_payment: symfony_workflow
        sylius_order: symfony_workflow
```

### Callback URL

MONEI posts each payment result to `https://<your store>/payment-methods/<payment method code>?payment=<Sylius payment id>`. This is the Sylius payment notify route. The plugin sets this URL on every MONEI payment.

- The URL must answer without a redirect. MONEI counts a 3xx answer as a failed delivery. Check your HTTPS redirect, `www` redirect and proxy rules for this path.
- Sylius builds the URL from the current request. Behind a reverse proxy or load balancer that ends TLS, configure Symfony's [trusted proxies](https://symfony.com/doc/current/deployment/proxies.html). Otherwise the URL starts with `http://`, your HTTPS redirect answers MONEI with a 3xx, and the delivery fails.
- MONEI signs the callback with your API key. The plugin refuses an unsigned or changed callback.

### Synchronous commands

The plugin's payment request commands always run in the web request. This is also true when your store sends the `payment_request` transport to an async queue. The pay step needs the buyer's IP address and browser, and must get the MONEI payment page URL at once. You do not have to configure this.

## Installation

### 1. Install the package

```bash
composer require monei/sylius-monei-plugin:^2.0
```

Keep the `^2.0` constraint. Without it, Composer can select 1.x on an older Sylius. Version 1.x does not work.

### 2. Register the bundle

Symfony Flex usually adds this line. Check `config/bundles.php` and add it if it is missing.

```php
// config/bundles.php
return [
    // ...
    Monei\SyliusPlugin\MoneiSyliusPlugin::class => ['all' => true],
];
```

### 3. Import the configuration

```yaml
# config/packages/monei_sylius.yaml
imports:
    - { resource: '@MoneiSyliusPlugin/config/config.yaml' }
```

The file is empty in 2.0.0. The import lets later versions add configuration without an extra step.

### 4. Import the routes

```yaml
# config/routes/monei_sylius.yaml
monei_sylius:
    resource: '@MoneiSyliusPlugin/config/routes.yaml'
```

Do not add a `prefix`, and do not put this import under the shop's `/{_locale}` prefix. The plugin serves the Apple Pay domain association file, and Apple reads it only at the domain root: `/.well-known/apple-developer-merchantid-domain-association`.

### 5. Install the assets and clear the cache

```bash
bin/console assets:install
bin/console cache:clear
```

`assets:install` copies the logo files to `public/bundles/moneisyliusplugin/`. Sylius-Standard runs it in its Composer `auto-scripts`.

The plugin adds no database tables, so it needs no migration.

### Web server and content security policy

- Hardened web server configurations often block paths that start with a dot, for example nginx `location ~ /\.`. Let `/.well-known/` through to `public/index.php`, or Apple Pay cannot read the domain association file.
- The onsite mode loads `https://js.monei.com/v3/monei.js` on the pay page, and on the checkout Complete step for inline payment. If your store sends a content security policy, allow that origin.
- To show the theme's exact web font in the onsite card fields, serve the font files with `Access-Control-Allow-Origin: https://js.monei.com` (or `*`). Without it, the card fields use the fallback fonts of the same font stack.

## Payment method setup

Create one Sylius payment method for each MONEI method that you offer.

1. In the Sylius admin, go to **Configuration → Payment methods**.
2. Click **Create** and select **MONEI**.
3. Fill in the fields:

   | Field | Description |
   | --- | --- |
   | API key | Your MONEI API key. `pk_test_...` makes test payments, `pk_live_...` makes live payments. |
   | MONEI payment method | Card, Bizum, PayPal, Apple Pay / Google Pay, MB WAY or Multibanco. |
   | Mode | **Hosted payment page** or **Onsite (monei.js)**. MB WAY and Multibanco are hosted only. |
   | Authorize only, capture later | The payment is held, and you capture it from the order page. Card, Apple Pay / Google Pay and PayPal only. |
   | Card field layout | **Separate fields** (default: card number, expiry date and CVC in separate boxes) or **Single line**. Card in onsite mode only. The card fields take the size, colours, font and focus style of your theme's inputs. |
   | Order ID prefix | Optional. Added before the order number that MONEI receives. At most 20 letters, digits, `-` and `_`. |
   | Account ID | Optional. Your MONEI account ID (a UUID), from the [MONEI Dashboard](https://dashboard.monei.com/), under Settings, API Access. It must be the account ID of the same MONEI account and mode (test or live) as the API key. Otherwise inline payment does not work. In onsite mode, the payment form then shows on the checkout Complete step. See [Inline payment](#inline-payment). Leave empty to keep the separate pay page. |

4. Enable the payment method in your channels.

The form checks only the format of the API key. It does not check the key with MONEI. Make sure that the key is correct and that the MONEI method is enabled on your MONEI account.

### Order ID prefix

MONEI refuses a new payment for an order ID that already has a paid or authorized payment. This check never expires. When several stores, or a staging copy of your store, use the same MONEI account, give each one a different prefix. Otherwise order number 000001 of one store blocks order number 000001 of the other.

The MONEI order ID is the prefix and the order number. For MB WAY, Multibanco and PayPal, the plugin also adds the Sylius payment id, so that a buyer can change method after opening a MONEI page. MONEI accepts at most 40 characters: letters, digits and `_ @ . / # & + -`. A payment for an order number with other characters (for example a space) fails, and the plugin logs the reason.

## Inline payment

An onsite payment method with an Account ID uses inline payment. Card, Bizum, PayPal and Apple Pay / Google Pay only. MB WAY and Multibanco always use the hosted page.

1. On the checkout Complete step, the monei.js component shows under the order summary.
   - **Card:** the cardholder name and the card fields show above Place order. Place order makes a one-time token, then places the order.
   - **Bizum, PayPal, Apple Pay / Google Pay:** when monei.js reports its button ready, the button replaces Place order. The buyer approves, and the order is placed.
2. The next page shows "Processing payment…" and confirms the payment with the token. monei.js runs 3D Secure, Bizum or PayPal when necessary.
3. The buyer goes to the thank-you page.

The token and the cardholder name go from the browser to MONEI. They do not go to your store's server.

The plugin uses the separate pay page, as without an Account ID, in these cases:

- JavaScript is off, monei.js does not load, or the component is not ready. Place order stays and places the order without a token.
- The buyer is not in Spain and selects Bizum. MONEI shows the inline Bizum button only to buyers it locates in Spain (by IP). Other buyers keep Place order. On the separate pay page MONEI uses the billing address country, so a buyer with a billing address in Spain can pay with Bizum there.
- The buyer pays again from the order page (`/order/{token}`).
- The order comes from the shop API.
- MONEI refuses the token (for example, an expired token). The pay page shows its fields and the error, and the buyer pays there.

## Authorize and capture

With **Authorize only, capture later**, MONEI holds the amount and the Sylius payment is `authorized`.

- **Capture:** click **Complete** on the payment, on the admin order page. The plugin captures the full amount at MONEI. When MONEI refuses, the payment stays `authorized` and the admin shows MONEI's reason.
- **Release:** cancel the order. The plugin cancels the hold at MONEI.
- MONEI releases an uncaptured hold after some days. See the MONEI documentation for the limits of each method.

## Refunds

- **Without `sylius/refund-plugin`:** click **Refund** on a completed payment. The plugin refunds the rest of the payment at MONEI (the amount minus what MONEI already refunded).
- **With `sylius/refund-plugin` (2.2 or later):** the refund plugin replaces the core Refund button. Make full and partial refunds from its refund page, and select the MONEI payment method that paid the order as the refund method. The plugin adds the `monei` gateway to the refund plugin's supported gateways.

The plugin calls MONEI first. When MONEI refuses, Sylius records nothing and the admin shows MONEI's reason.

## Apple Pay domain

When you save an enabled Apple Pay / Google Pay payment method, the plugin registers your domains with MONEI for Apple Pay:

- the hostname of each channel of the payment method;
- the host of the admin request, for a channel without a hostname.

When MONEI refuses a domain, the admin shows a warning and the save still completes. Apple Pay does not show on that domain until it is registered. Save the payment method again to retry. When you add a channel to the payment method or change a channel hostname, save the payment method again to register the new domain. Apple Pay needs HTTPS and the route import from [step 4](#4-import-the-routes). Google Pay needs no domain registration.

## How it works

- **Pay.** The plugin creates a MONEI payment for the Sylius payment and sends the buyer to the MONEI page (hosted) or shows the monei.js form (onsite). When the buyer pays again, the plugin reuses a pending MONEI payment, or cancels it and creates a new one when the method or amount changed.
- **Status.** The plugin reads the status from the MONEI API on the return page, on each callback and before each admin action. It does not trust the callback body. A pending MONEI payment keeps the Sylius payment `new`, so the buyer can pay again.

  | MONEI status | Sylius payment |
  | --- | --- |
  | Succeeded | `completed` |
  | Authorized | `authorized` |
  | Pending | `new` (no change) |
  | Failed, Expired | `failed` |
  | Canceled | `cancelled` |
  | Refunded | `refunded` |
  | Partially refunded | `completed` (Sylius has no partial state) |

- **Amount check.** Before it completes or authorizes a payment, the plugin compares the MONEI amount and currency with the Sylius payment. On a difference it changes nothing and logs a critical message.
- **Expiry.** A MONEI payment expires one hour before Sylius may cancel the unpaid order (`sylius_order.expiration.order`, default 5 days), and after 7 days at most.
- **Order cancel.** Before Sylius cancels an order, the plugin checks its MONEI payments. It refuses the cancel when MONEI took the money, cancels a pending MONEI payment, and releases an authorized hold.
- **Admin API.** The admin API (`/api/v2/admin/payments/{id}/complete`, `/api/v2/admin/payments/{id}/refund`, `/api/v2/admin/orders/{tokenValue}/cancel`) follows the same rules. A MONEI refusal answers 409 with the reason.
- **Logs.** The plugin logs on the Monolog channel `monei`. Send its `critical` messages to an alert: each one is a payment that needs a manual action in the MONEI dashboard.

## Limits

- **Currencies with 2 decimals only.** Sylius stores every amount with 2 decimals. A payment in a currency with 0 or 3 decimals fails before any call to MONEI.
- **No dashboard webhook.** The plugin uses only the callback URL that it sets on each payment. Do not add a webhook for Sylius in the MONEI dashboard: its events carry no Sylius payment id, and the plugin refuses them.
- **Actions in the MONEI dashboard are not recorded in Sylius.** MONEI sends no callback for refunds, captures, voids or expiry. The return page and the plugin's admin actions still read the current MONEI status.
- **A refund in the MONEI dashboard blocks the next refund plugin refund.** With `sylius/refund-plugin`, the plugin refuses a MONEI refund when MONEI refunded more than Sylius recorded. Record the dashboard refund first, with an offline refund method, then make the next refund.
- **Captures and refunds use the API key that created the payment.** Do not delete a MONEI payment method, or change its API key to a key of another MONEI account, while it has payments to capture or refund. The plugin then cannot read those payments and refuses the action.
- **Changing Authorize only on a payment method with open payments.** A buyer who pays again after the change gets a new payment of the new type. When the old payment is already with the payment processor (for example a card payment in 3D Secure, or an open PayPal approval), MONEI cannot cancel it. The buyer then sees that a payment is in progress until the old payment ends or expires.
- **Capture is always for the full amount.** The plugin has no partial capture.
- **Duplicate refunds.** MONEI ignores a second refund of the same payment and the same amount sent within 3 seconds. The plugin then refuses the refund and shows a message, so Sylius does not record a refund that MONEI did not make. Check the payment in the MONEI dashboard before you try again.
- **A payment that is already with the payment processor cannot be cancelled.** This applies, for example, to an open Multibanco reference, an open MB WAY request or an open PayPal approval. Such a payment stays payable until it expires:
  - **After a method change.** A buyer opens a Multibanco, MB WAY or PayPal page and then pays with another method. The old payment cannot be cancelled. The buyer can still pay it. If the buyer pays it, the plugin logs a critical message. Refund that payment in the MONEI dashboard. The same applies when the buyer changes to a payment method of another gateway. No MONEI code runs on that change.
  - **After an order cancel.** The order cancel goes on and the admin shows a warning. If the buyer pays the payment later, the plugin logs a critical message. Refund it in the MONEI dashboard.
- **Payment deadline.** A pay attempt in the last hour before the order's payment deadline gets a MONEI expiry up to 1 hour after that deadline, because the plugin gives each MONEI payment at least one hour. A payment that succeeds on an order that Sylius already cancelled changes nothing in Sylius, and the plugin logs a critical message. Refund it in the MONEI dashboard.
- **A hold voided in the MONEI dashboard blocks the order.** MONEI then refuses every new payment for that order ID, so the buyer cannot pay that order again. Create a new order.
- **A refused order cancel does not record the payment.** When the admin cancels an order and MONEI says that it is paid, the cancel is refused, but the payment stays unpaid in Sylius until the callback or the next `sylius:cancel-unpaid-orders` run records it.
- **Orders with more than one MONEI payment.** When an order cancel releases the hold of one payment and is then refused for a second payment, Sylius rolls back, but the first hold is gone at MONEI. Sylius orders normally have one payment.
- **Refund plugin steps after MONEI.** With `sylius/refund-plugin`, MONEI refunds first. If a later step fails (credit memo, e-mail, database), MONEI returned the money but Sylius has no record. The next MONEI refund of that order is refused. Record the missing refund with an offline refund method.
- **No protection against parallel actions in the admin API and the unpaid-orders command.** The admin pages, the pay step, the return page and the callback wait for each other on the same payment. The admin API and `sylius:cancel-unpaid-orders` do not.
- **Apple Pay / Google Pay have no end-to-end payment test.** No automated test can approve a wallet payment. The end-to-end wallet tests only check that the Google Pay button shows, on the pay page and on the Complete step. No end-to-end test covers Apple Pay or wallets on the hosted payment page.
- **Inline PayPal and wallet approval comes before the order.** The buyer approves on the Complete step, before Sylius places the order. If Place order then fails, the buyer must approve again.
- **The inline token stays in the browser tab for up to 15 minutes.** The Complete step stores the token and the cardholder name in the tab's `sessionStorage`. The next page reads and deletes them. An entry older than 15 minutes is not used, and the pay page shows its fields.
- **MB WAY and Multibanco have no end-to-end tests.** The MONEI test account of the suite cannot pay them. Unit and functional tests cover them.

## Testing

Every PHP command runs in Docker through the `Makefile`. You do not need PHP on your computer.

```bash
make install       # Composer install, payment encryption key, test database
make test          # unit and functional tests
make test-lowest   # the same tests on PHP 8.2 and Sylius 2.2.13
make analyse       # PHPStan
make cs            # PHP CS Fixer, dry run
```

The end-to-end suite pays against the MONEI test API in a real Sylius store in Docker. Put a **test mode** API key (`pk_test_...`) in `e2e/.env` (copy `e2e/.env.example`). The suite refuses a live key.

```bash
pnpm --dir e2e install
pnpm --dir e2e exec playwright install chromium
pnpm --dir e2e e2e:start
pnpm --dir e2e e2e
pnpm --dir e2e e2e:stop
```

See [e2e/README.md](e2e/README.md). CI runs the unit and functional tests on every pull request. The end-to-end suite runs every night and on pull requests from branches of this repository, on Sylius 2.2.13 with PHP 8.2 and on Sylius 2.3 with PHP 8.4.

To test the payment flow in your store, use a `pk_test_` key and the [MONEI test cards](https://docs.monei.com/testing/).

## Upgrade from 1.x

Version 1.x did not load in Sylius. It failed before any call to MONEI, so it took no payment. There is no data to migrate.

1. Make sure that your store runs Sylius 2.2.13 or later, or 2.3.3 or later. Version 2.0.0 does not support Sylius 2.1.
2. Make sure that `sylius_payment` and `sylius_order` use the `symfony_workflow` state machine adapter. See [State machine adapter](#state-machine-adapter).
3. Delete the 1.x route import, usually `config/routes/monei.yaml` (it imports `@MoneiSyliusPlugin/config/routes.xml` under `/monei`). Do this before you update: version 2.0.0 has no `routes.xml`, and `composer require` clears the cache.
4. Update the package: `composer require monei/sylius-monei-plugin:^2.0`.
5. Add the configuration import from [step 3](#3-import-the-configuration) and the route import from [step 4](#4-import-the-routes).
6. If you have a payment method with the MONEI gateway from 1.x, delete it.
7. Create one payment method for each MONEI method. See [Payment method setup](#payment-method-setup).

Changes:

- The gateway config keys `account_id`, `sandbox` and `integration_type` are removed. Version 2.2.0 adds a new, optional `account_id` for [inline payment](#inline-payment). The API key prefix selects test or live mode. The mode (hosted or onsite) is set per payment method.
- Translations are English and Spanish. 1.x also had Catalan and French.
- The plugin uses the Sylius Payment Requests API, not Payum.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

This plugin is open-source software under the [MIT license](LICENSE).

The card, Apple Pay, Google Pay and PayPal logos (`public/images/{visa,master_card,amex,apple_pay,google_pay,paypal}.svg`) come from Sylius AdminBundle. Copyright (c) 2011-present Sylius Sp. z o.o., MIT license ([Sylius](https://github.com/Sylius/Sylius)).

## Links

- [Live demo](https://sylius-demo.monei.com) (MONEI test mode)
- [MONEI Sylius documentation](https://docs.monei.com/e-commerce/sylius)
- [MONEI documentation](https://docs.monei.com/)
- [MONEI Dashboard](https://dashboard.monei.com/)
- [MONEI PHP SDK](https://github.com/MONEI/monei-php-sdk)
- [Sylius documentation](https://docs.sylius.com/)
