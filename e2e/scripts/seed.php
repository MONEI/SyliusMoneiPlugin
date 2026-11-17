<?php

declare(strict_types=1);

// Runs in the `seed` container after the fixtures (scripts/start.sh): adds one MONEI payment
// method per method and mode the specs use, fixed prices and the admin user. Prints the generated
// values as JSON. Never prints the API key or the admin password; errors name the exception class
// and message only.

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\TestApplication\Kernel;

require '/app/vendor/autoload.php';
require '/app/vendor/sylius/test-application/config/bootstrap.php';

set_exception_handler(static function (Throwable $exception): never {
    fwrite(\STDERR, sprintf("seed: %s: %s\n", $exception::class, $exception->getMessage()));
    exit(1);
});

/** Sylius payment method code => gateway config without the key and prefix. */
const PAYMENT_METHODS = [
    'monei_card_hosted' => ['name' => 'MONEI card', 'payment_method' => 'card', 'mode' => 'hosted', 'use_authorize' => false],
    'monei_bizum_hosted' => ['name' => 'MONEI Bizum', 'payment_method' => 'bizum', 'mode' => 'hosted', 'use_authorize' => false],
    'monei_paypal_hosted' => ['name' => 'MONEI PayPal', 'payment_method' => 'paypal', 'mode' => 'hosted', 'use_authorize' => false],
    'monei_card_onsite' => ['name' => 'MONEI card onsite', 'payment_method' => 'card', 'mode' => 'onsite', 'use_authorize' => false],
    'monei_bizum_onsite' => ['name' => 'MONEI Bizum onsite', 'payment_method' => 'bizum', 'mode' => 'onsite', 'use_authorize' => false],
    'monei_paypal_onsite' => ['name' => 'MONEI PayPal onsite', 'payment_method' => 'paypal', 'mode' => 'onsite', 'use_authorize' => false],
    'monei_wallet_onsite' => ['name' => 'MONEI wallet onsite', 'payment_method' => 'apple_google_pay', 'mode' => 'onsite', 'use_authorize' => false],
    'monei_card_auth' => ['name' => 'MONEI card AUTH', 'payment_method' => 'card', 'mode' => 'hosted', 'use_authorize' => true],
];

const ADMIN_EMAIL = 'e2e-admin@monei-e2e.test';

/** Product variant code => price in cents. Bizum test payments are approved only up to 5 EUR. */
const PRICES = [
    'E2E_T_SHIRT-variant-0' => 1800,
    'E2E_STICKER-variant-0' => 250,
];

$apiKey = trim((string) getenv('MONEI_TEST_API_KEY'));
if (!str_starts_with($apiKey, 'pk_test_')) {
    throw new RuntimeException('MONEI_TEST_API_KEY is missing or is not a pk_test_ key.');
}

// MONEI's duplicate check never expires on the shared sandbox account: every run needs its own prefix.
$runId = (string) getenv('E2E_RUN_ID');
if (1 !== preg_match('/^[a-z0-9]{1,12}$/D', $runId)) {
    throw new RuntimeException('E2E_RUN_ID must be 1 to 12 lowercase letters or digits.');
}
$orderIdPrefix = sprintf('e2e%s-', $runId);

// Random per run (scripts/start.sh): the tunnel makes /admin public.
$adminPassword = (string) getenv('E2E_ADMIN_PASSWORD');
if (strlen($adminPassword) < 32) {
    throw new RuntimeException('E2E_ADMIN_PASSWORD must hold at least 32 characters.');
}

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();
$entityManager = $container->get('doctrine')->getManager();

/** @var ChannelInterface $channel */
$channel = $container->get('sylius.repository.channel')->findOneByCode('E2E');

$paymentMethods = [];
$position = 0;
foreach (PAYMENT_METHODS as $code => $config) {
    /** @var PaymentMethodInterface $paymentMethod */
    $paymentMethod = $container->get('sylius.factory.payment_method')->createWithGateway('monei');
    $paymentMethod->setCode($code);
    $paymentMethod->setCurrentLocale('en_US');
    $paymentMethod->setFallbackLocale('en_US');
    $paymentMethod->setName($config['name']);
    $paymentMethod->setPosition($position++);
    $paymentMethod->setEnabled(true);
    $paymentMethod->addChannel($channel);
    $paymentMethod->getGatewayConfig()->setGatewayName($code);
    $paymentMethod->getGatewayConfig()->setConfig([
        'api_key' => $apiKey,
        'payment_method' => $config['payment_method'],
        'mode' => $config['mode'],
        'use_authorize' => $config['use_authorize'],
        'order_id_prefix' => $orderIdPrefix,
    ]);
    $entityManager->persist($paymentMethod);
    $paymentMethods[$code] = ['name' => $config['name']];
}

foreach (PRICES as $variantCode => $price) {
    /** @var ProductVariantInterface $variant */
    $variant = $container->get('sylius.repository.product_variant')->findOneBy(['code' => $variantCode]);
    $variant->getChannelPricingForChannel($channel)->setPrice($price);
}

/** @var AdminUserInterface $admin */
$admin = $container->get('sylius.factory.admin_user')->createNew();
$admin->setEmail(ADMIN_EMAIL);
$admin->setUsername(ADMIN_EMAIL);
$admin->setPlainPassword($adminPassword);
$admin->setEnabled(true);
$admin->setLocaleCode('en_US');
$entityManager->persist($admin);

$entityManager->flush();

echo json_encode(['orderIdPrefix' => $orderIdPrefix, 'paymentMethods' => $paymentMethods, 'adminEmail' => ADMIN_EMAIL], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT), "\n";
