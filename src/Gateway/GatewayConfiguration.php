<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Gateway;

use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/** The gateway config of a Sylius payment method with the `monei` factory. */
final readonly class GatewayConfiguration
{
    public const FACTORY_NAME = 'monei';

    public const API_KEY = 'api_key';
    public const METHOD = 'payment_method';
    public const MODE = 'mode';
    public const USE_AUTHORIZE = 'use_authorize';

    public const MODE_HOSTED = 'hosted';
    public const MODE_ONSITE = 'onsite';

    private function __construct(
        public string $apiKey,
        public MoneiMethod $method,
        public bool $onsite,
        public bool $authorize,
    ) {
    }

    public static function isMonei(?PaymentMethodInterface $paymentMethod): bool
    {
        return self::FACTORY_NAME === $paymentMethod?->getGatewayConfig()?->getFactoryName();
    }

    public static function fromPaymentMethod(PaymentMethodInterface $paymentMethod): self
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface || self::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
            throw new \InvalidArgumentException(sprintf('Payment method "%s" is not a MONEI payment method.', (string) $paymentMethod->getCode()));
        }

        return self::fromArray($gatewayConfig->getConfig());
    }

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        $apiKey = $config[self::API_KEY] ?? null;
        if (!is_string($apiKey) || '' === trim($apiKey)) {
            throw new \InvalidArgumentException('The MONEI API key is missing.');
        }

        $method = MoneiMethod::tryFrom(is_string($config[self::METHOD] ?? null) ? $config[self::METHOD] : '');
        if (null === $method) {
            throw new \InvalidArgumentException('The MONEI payment method is missing or unknown.');
        }

        $onsite = self::MODE_ONSITE === ($config[self::MODE] ?? self::MODE_HOSTED);
        $authorize = true === ($config[self::USE_AUTHORIZE] ?? false);

        // The form refuses these combinations; a config written another way must not get them.
        if ($onsite && !$method->supportsOnsite()) {
            throw new \InvalidArgumentException(sprintf('MONEI %s has no onsite mode.', $method->value));
        }
        if ($authorize && !$method->supportsAuthorize()) {
            throw new \InvalidArgumentException(sprintf('MONEI %s cannot authorize; it always charges at once.', $method->value));
        }

        return new self(trim($apiKey), $method, $onsite, $authorize);
    }
}
