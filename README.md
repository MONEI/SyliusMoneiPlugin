<p align="center">
    <a href="https://monei.com" target="_blank">
        <img src="https://assets.monei.com/images/logo.svg" width="200" alt="MONEI">
    </a>
</p>

<h1 align="center">MONEI Payment Plugin for Sylius</h1>

<p align="center">
    Accept payments with <a href="https://monei.com">MONEI</a> in your Sylius store — Cards, Bizum, Apple Pay, Google Pay.
    <br>
    MONEI is a licensed Payment Institution (Banco de España #6911).
</p>

<p align="center">
    <a href="https://github.com/MONEI/SyliusMoneiPlugin/actions"><img src="https://github.com/MONEI/SyliusMoneiPlugin/workflows/CI/badge.svg" alt="Build Status"></a>
    <a href="https://packagist.org/packages/monei/sylius-monei-plugin"><img src="https://poser.pugx.org/monei/sylius-monei-plugin/v/stable" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/monei/sylius-monei-plugin"><img src="https://poser.pugx.org/monei/sylius-monei-plugin/license" alt="License"></a>
    <img src="https://img.shields.io/badge/Sylius-%5E2.1-blue" alt="Sylius Version">
    <img src="https://img.shields.io/badge/PHP-%5E8.2-purple" alt="PHP Version">
</p>

---

## Features

- **Two integration modes** — Hosted Payment Page (redirect) or Embedded MONEI Component (JS widget), configurable per gateway in the Sylius admin
- **All MONEI payment methods** — Cards (Visa, Mastercard, Amex), Bizum, Apple Pay, Google Pay
- **Full lifecycle support** — Capture, Refund (full & partial), Cancel, and Status sync
- **Webhook-driven** — Asynchronous payment status updates via signed MONEI webhooks
- **PSD2 / SCA ready** — 3D Secure handled automatically by MONEI
- **Official SDK** — Built on the [`monei/monei-php-sdk`](https://packagist.org/packages/monei/monei-php-sdk) for reliable API communication and signature verification
- **Multi-language** — Admin translations in English, Spanish, Catalan, and French

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.2+ |
| Sylius | 2.1+ |
| Symfony | 6.4 or 7.x |

## Installation

### 1. Install via Composer

```bash
composer require monei/sylius-monei-plugin
```

### 2. Register the bundle

If your Sylius project doesn't use Symfony Flex, add the bundle manually:

```php
// config/bundles.php
return [
    // ...
    Monei\SyliusPlugin\MoneiSyliusPlugin::class => ['all' => true],
];
```

### 3. Import the plugin routes (optional)

```yaml
# config/routes/monei.yaml
monei_sylius:
    resource: "@MoneiSyliusPlugin/config/routes.xml"
    prefix: /monei
```

> Payum handles the webhook notify route automatically — no additional route config is needed for basic operation.

### 4. Configure MONEI in the Sylius Admin

1. Go to **Configuration → Payment methods**
2. Click **Create** and choose **MONEI** as the gateway
3. Fill in your **API Key** and **Account ID** (find these at [dashboard.monei.com](https://dashboard.monei.com/settings/api))
4. Select your preferred **Integration Type**: Redirect or Embedded Component
5. Toggle **Sandbox mode** for testing
6. Assign the payment method to your desired channels

That's it — your store is now accepting payments via MONEI.

## Configuration Reference

| Field | Description |
|---|---|
| `api_key` | Your MONEI API key (`pk_test_...` for sandbox, `pk_live_...` for production) |
| `account_id` | Your MONEI account identifier |
| `integration_type` | `redirect` (hosted page) or `component` (embedded MONEI.js widget) |
| `sandbox` | Enable test mode — no real charges are processed |

## How It Works

### Redirect Flow (Hosted Payment Page)

1. Customer clicks "Pay" → Sylius creates a MONEI payment via the API
2. Customer is redirected to MONEI's PCI-compliant hosted page
3. Customer completes payment (card, Bizum, Apple Pay, Google Pay)
4. MONEI redirects back to your store's completion URL
5. MONEI sends a webhook to confirm the final status asynchronously

### Component Flow (Embedded MONEI.js)

1. Customer clicks "Pay" → Sylius creates a MONEI payment via the API
2. An in-page payment form is rendered using MONEI.js with card input, Bizum, Apple Pay, and Google Pay buttons
3. Customer completes payment without leaving the page
4. On success, the customer is redirected to the order confirmation page
5. MONEI sends a webhook to confirm the final status asynchronously

## Webhooks

MONEI uses webhooks to notify your store of payment status changes. The plugin automatically:

1. Verifies the `MONEI-Signature` header using the official SDK's signature verification (timestamp-based HMAC-SHA256)
2. Re-fetches the payment from MONEI's API to prevent spoofing
3. Updates the Sylius payment status accordingly

Ensure your server is accessible from the internet so MONEI can reach the callback URL.

## Testing

```bash
# Run all tests
make test

# Run only unit tests
make test-unit

# Static analysis
make analyse

# Code style check
make check

# Auto-fix code style
make fix
```

Use MONEI sandbox credentials (`pk_test_...`) and [MONEI test cards](https://docs.monei.com/docs/testing/) to test the full flow without real charges.

## Architecture

```
SyliusMoneiPlugin/
├── config/
│   ├── routes.xml                     # Plugin routes
│   └── services.xml                   # Service definitions
├── src/
│   ├── Action/
│   │   ├── CaptureAction.php          # Creates payment, redirect or component
│   │   ├── ConvertPaymentAction.php   # Maps Sylius Payment → MONEI params
│   │   ├── NotifyAction.php           # Processes MONEI webhooks
│   │   ├── RefundAction.php           # Triggers refunds via MONEI API
│   │   └── StatusAction.php           # Maps MONEI status → Sylius status
│   ├── Client/
│   │   └── MoneiApiClient.php         # Thin wrapper around monei-php-sdk
│   ├── DependencyInjection/           # Symfony DI wiring
│   ├── Factory/
│   │   └── MoneiGatewayFactory.php    # Payum gateway factory registration
│   ├── Form/Type/
│   │   └── MoneiGatewayConfigurationType.php  # Admin config form
│   ├── Resolver/
│   │   └── PaymentStatusResolver.php  # Clean MONEI → Sylius status mapping
│   └── Resources/
│       └── translations/              # en, es, ca, fr
└── tests/
    ├── Unit/                          # PHPUnit unit tests
    └── Integration/                   # PHPUnit integration tests
```

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

This plugin is open-sourced software licensed under the [MIT license](LICENSE).

## Links

- [MONEI Documentation](https://docs.monei.com/)
- [MONEI Dashboard](https://dashboard.monei.com/)
- [MONEI PHP SDK](https://github.com/MONEI/monei-php-sdk)
- [Sylius Documentation](https://docs.sylius.com/)
- [Sylius Plugin Development Guide](https://docs.sylius.com/plugins-development-guide/how-to-create-a-plugin-for-sylius)
