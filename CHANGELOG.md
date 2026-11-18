# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.0] - TBD

### Added

- Optional **Account ID** gateway setting (a UUID). An onsite payment method with an Account ID uses inline payment: the card fields, or the Bizum, PayPal or Apple Pay / Google Pay button, show on the checkout Complete step. The token goes from the browser to MONEI, not to the store's server.
- After an inline Place order, the pay page shows "Processing payment…" and confirms the payment with monei.js. When there is no usable token, the pay page works as in 2.1.
- Method logos next to each MONEI method on the checkout Payment step, and a "Test mode" badge for a `pk_test_` key.
- On Sylius 2.3, the admin payment method list shows the MONEI logo and the method logos, unless your store sets its own template for the name field of the `sylius_admin_payment_method` grid.

### Changed

- Requires Sylius 2.2.13 or later on the 2.2 line, or Sylius 2.3.3 or later (`~2.2.13 || ^2.3.3`). These versions fix the Sylius security advisories of 2026-11-18: PKSA-4j8y-7y7g-12nz, PKSA-4bv5-whyg-w8m1 and PKSA-9hkr-rfsw-bmqz.
- The onsite pay page templates changed: the card fields and their script are shared partials in `templates/shop/shared/`, and the page confirms the inline token. If you override them, copy the changes again.
- The English cardholder label is "Name on card". The card field labels show the required asterisk.
- The logo files ship in `public/images/`. Run `bin/console assets:install` after the update (Sylius-Standard runs it in its Composer `auto-scripts`).

## [2.1.0] - 2026-11-17

### Added

- Card field layout option for the onsite card: **Separate fields** (default, monei.js CardGroup: card number, expiry date and CVC) or **Single line**.
- The onsite card fields look like the theme's own inputs: they get the label, box, size, colours, font and focus style of the cardholder name input. The theme's web font loads inside the card fields; a font too large for an inline copy is loaded by its URL, which needs `Access-Control-Allow-Origin: https://js.monei.com` on the font files.

### Changed

- Existing onsite card payment methods show separate card fields after the update. Set **Card field layout** to **Single line** to keep one field.

## [2.0.0] - 2026-11-17

Full rewrite. See the README section "Upgrade from 1.x".

### Added

- PayPal, MB WAY and Multibanco, next to card, Bizum and Apple Pay / Google Pay.
- Hosted or onsite (monei.js v3) mode per payment method. MB WAY and Multibanco are hosted only.
- Authorize and capture: capture with the admin Complete button, release with an order cancel.
- Refunds with the core Refund button, and full and partial refunds with `sylius/refund-plugin` 2.2 or later.
- A refund that MONEI answers without refunding (an equal refund sent within 3 seconds) is refused, so Sylius does not record it.
- Order cancel guard: refuses the cancel of an order that MONEI says is paid, and cancels its pending MONEI payment.
- MONEI refusals show as an admin flash message, or as a 409 answer in the admin API. The Sylius state does not change.
- The pay step, the return page, the callback and the admin pages lock the payment row, so they do not act on one payment at the same time.
- Apple Pay domain registration when an Apple Pay / Google Pay payment method is saved, and the domain association file route.
- Optional order ID prefix, for several stores on one MONEI account.
- MONEI payments expire before Sylius cancels the unpaid order.
- Amount and currency check before a payment is completed or authorized.
- Monolog channel `monei`.
- `MONEI/Sylius/<version>` User-Agent on MONEI API requests.
- Docker `Makefile` for unit, functional and static checks, and an end-to-end suite against the MONEI test API. CI runs it every night and on pull requests from branches of this repository.

### Changed

- **BREAKING:** Requires Sylius 2.2.12 or later on the 2.2 line, or Sylius 2.3.2 or later. These versions fix Sylius security advisories.
- **BREAKING:** Requires the `symfony_workflow` state machine adapter for the `sylius_payment` and `sylius_order` graphs. The container does not build when either graph is mapped to another adapter.
- **BREAKING:** Uses the Sylius Payment Requests API instead of Payum.
- **BREAKING:** One Sylius payment method per MONEI method. Delete the 1.x MONEI payment method and create one per method.
- **BREAKING:** New gateway config keys: `payment_method`, `mode` (`hosted` or `onsite`), `use_authorize`, `order_id_prefix`.
- **BREAKING:** Routes: import `@MoneiSyliusPlugin/config/routes.yaml` without a prefix.
- **BREAKING:** Payment callbacks go only to the callback URL set on each payment. A webhook in the MONEI dashboard is not supported.

### Removed

- **BREAKING:** Sylius 2.1 support.
- **BREAKING:** Gateway config keys `account_id`, `sandbox` and `integration_type`.
- **BREAKING:** `config/routes.xml`.
- **BREAKING:** Catalan and French translations. English and Spanish remain.

### Fixed

- The plugin loads in Sylius. 1.x did not load: it failed before any call to MONEI and took no payment.

## [1.0.1] - 2026-09-29

1.0.1 did not load in Sylius either, so no store ran these fixes. See 2.0.0.

### Fixed

- Embedded component flow loads monei.js v3, so the MONEI Flex installment choice shows in the payment modal
- Embedded component page no longer fails on load; card, Bizum and Apple Pay / Google Pay payments tokenize and confirm the payment
- Payment creation no longer fails reading nested API fields (e.g. `nextAction.redirectUrl`), so both the redirect and the embedded flow start
- Payment status sync: webhooks and status checks fetch the payment with the SDK's `get()`; `getPayment()` does not exist, so every webhook failed

## [1.0.0] - TBD

### Added

- Payum gateway factory for MONEI (`monei` gateway type)
- Redirect flow via MONEI Hosted Payment Page
- Embedded component flow via MONEI.js widget
- Card payments (Visa, Mastercard, Amex)
- Bizum support
- Apple Pay and Google Pay support
- Full and partial refund support from the Sylius admin
- Webhook-driven payment status updates with SDK signature verification
- Admin configuration form (API key, account ID, integration type, sandbox mode)
- Translations: English, Spanish, Catalan, French
- Unit tests and integration tests
- PHPStan level 7 static analysis
- PHP CS Fixer code style enforcement
- GitHub Actions CI matrix (PHP 8.2–8.4, Sylius 2.1–2.2)
