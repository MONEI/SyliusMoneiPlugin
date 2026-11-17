<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Gateway;

use Monei\Model\PaymentTransactionType;
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
    public const ORDER_ID_PREFIX = 'order_id_prefix';

    public const MODE_HOSTED = 'hosted';
    public const MODE_ONSITE = 'onsite';

    private function __construct(
        public string $apiKey,
        public MoneiMethod $method,
        public bool $onsite,
        public bool $authorize,
        public string $orderIdPrefix,
    ) {
    }

    public function transactionType(): string
    {
        return $this->authorize ? PaymentTransactionType::AUTH : PaymentTransactionType::SALE;
    }

    public static function isMonei(?PaymentMethodInterface $paymentMethod): bool
    {
        return self::FACTORY_NAME === $paymentMethod?->getGatewayConfig()?->getFactoryName();
    }

    public static function fromPaymentMethod(PaymentMethodInterface $paymentMethod): self
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface || self::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
            throw new \InvalidArgumentException(\sprintf('Payment method "%s" is not a MONEI payment method.', (string) $paymentMethod->getCode()));
        }

        return self::fromArray($gatewayConfig->getConfig());
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidGatewayConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $apiKey = $config[self::API_KEY] ?? null;
        $apiKey = \is_string($apiKey) ? trim($apiKey) : '';
        if ('' === $apiKey) {
            throw new InvalidGatewayConfigurationException(self::API_KEY, 'monei.gateway_configuration.api_key.missing', 'The MONEI API key is missing.');
        }
        if (1 !== preg_match('/^pk_(test|live)_\S+$/D', $apiKey)) {
            throw new InvalidGatewayConfigurationException(self::API_KEY, 'monei.gateway_configuration.api_key.invalid', 'The MONEI API key must start with pk_test_ or pk_live_.');
        }

        $method = MoneiMethod::tryFrom(\is_string($config[self::METHOD] ?? null) ? $config[self::METHOD] : '');
        if (null === $method) {
            throw new InvalidGatewayConfigurationException(self::METHOD, 'monei.gateway_configuration.payment_method.invalid', 'The MONEI payment method is missing or unknown.');
        }

        $mode = $config[self::MODE] ?? self::MODE_HOSTED;
        if (!\in_array($mode, [self::MODE_HOSTED, self::MODE_ONSITE], true)) {
            throw new InvalidGatewayConfigurationException(self::MODE, 'monei.gateway_configuration.mode.invalid', 'The MONEI mode must be hosted or onsite.');
        }
        $onsite = self::MODE_ONSITE === $mode;
        if ($onsite && !$method->supportsOnsite()) {
            throw new InvalidGatewayConfigurationException(self::MODE, 'monei.gateway_configuration.mode.hosted_only', \sprintf('MONEI %s has no onsite mode.', $method->value));
        }

        // Truthy, as Sylius's DefaultActionProvider reads it to pick the `authorize` action.
        $authorize = (bool) ($config[self::USE_AUTHORIZE] ?? false);
        if ($authorize && !$method->supportsAuthorize()) {
            throw new InvalidGatewayConfigurationException(self::USE_AUTHORIZE, 'monei.gateway_configuration.use_authorize.not_supported', \sprintf('MONEI %s cannot authorize; it always charges at once.', $method->value));
        }

        $orderIdPrefix = $config[self::ORDER_ID_PREFIX] ?? '';
        if (!\is_string($orderIdPrefix) || 1 !== preg_match('/^[A-Za-z0-9_-]{0,20}$/D', $orderIdPrefix)) {
            throw new InvalidGatewayConfigurationException(self::ORDER_ID_PREFIX, 'monei.gateway_configuration.order_id_prefix.invalid', 'The order ID prefix allows at most 20 letters, digits, - and _.');
        }

        return new self($apiKey, $method, $onsite, $authorize, $orderIdPrefix);
    }
}
