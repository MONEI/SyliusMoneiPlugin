# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

Full rewrite. See the README section "Upgrade from 1.x".

### Breaking changes

- Requires Sylius 2.2.12 or later on the 2.2 line, or Sylius 2.3.2 or later. These versions fix Sylius security advisories.
- Requires the `symfony_workflow` state machine adapter for the `sylius_payment` and `sylius_order` graphs. The container does not build when either graph is mapped to another adapter.
- Uses the Sylius Payment Requests API instead of Payum.
- One Sylius payment method per MONEI method. Delete the 1.x MONEI payment method and create one per method.
- Gateway config keys `account_id`, `sandbox` and `integration_type` are removed. New keys: `payment_method`, `mode` (`hosted` or `onsite`), `use_authorize`, `order_id_prefix`.
- Routes: import `@MoneiSyliusPlugin/config/routes.yaml` without a prefix. `config/routes.xml` is removed.
- Payment callbacks go only to the callback URL set on each payment. A webhook in the MONEI dashboard is not supported.
- Translations: English and Spanish. Catalan and French are removed.

### Added

- PayPal, MB WAY and Multibanco, next to card, Bizum and Apple Pay / Google Pay.
- Hosted or onsite (monei.js v3) mode per payment method. MB WAY and Multibanco are hosted only.
- Authorize and capture: capture with the admin Complete button, release with an order cancel.
- Refunds with the core Refund button, and full and partial refunds with `sylius/refund-plugin` 2.2 or later.
- Order cancel guard: refuses the cancel of an order that MONEI says is paid, and cancels its pending MONEI payment.
- MONEI refusals show as an admin flash message, or as a 409 answer in the admin API. The Sylius state does not change.
- Apple Pay domain registration when an Apple Pay / Google Pay payment method is saved, and the domain association file route.
- Optional order ID prefix, for several stores on one MONEI account.
- MONEI payments expire before Sylius cancels the unpaid order.
- Amount and currency check before a payment is completed or authorized.
- Monolog channel `monei`.
- `MONEI/Sylius/<version>` User-Agent on MONEI API requests.
- Docker `Makefile` for unit, functional and static checks, and an end-to-end suite against the MONEI test API, run every night in CI.

### Fixed

- The plugin loads in Sylius. 1.x did not load: it failed before any call to MONEI and took no payment.

## [1.0.1] - 2026-09-29

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
