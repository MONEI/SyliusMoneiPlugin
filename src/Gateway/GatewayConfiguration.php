<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Gateway;

use Monei\Model\PaymentTransactionType;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/** The gateway config of a Sylius payment method with the `monei` factory. */
final readonly class GatewayConfiguration
{
    public const FACTORY_NAME = 'monei';

    public const CREDENTIALS = 'credentials';
    public const MANUAL = 'manual';
    public const CONNECTED_LIVE = 'connected_live';
    public const CONNECTED_TEST = 'connected_test';
    public const SIGNING_SECRET = 'signing_secret';

    public const API_KEY = 'api_key';
    public const METHOD = 'payment_method';
    public const MODE = 'mode';
    public const USE_AUTHORIZE = 'use_authorize';
    public const ORDER_ID_PREFIX = 'order_id_prefix';
    public const CARD_LAYOUT = 'card_layout';
    public const ACCOUNT_ID = 'account_id';

    public const ACCOUNT_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    public const MODE_HOSTED = 'hosted';
    public const MODE_ONSITE = 'onsite';

    public const CARD_LAYOUT_SPLIT = 'split';
    public const CARD_LAYOUT_SINGLE = 'single';

    private function __construct(
        public string $apiKey,
        public MoneiMethod $method,
        public bool $onsite,
        public bool $authorize,
        public string $orderIdPrefix,
        /** Used by the card method in onsite mode only. */
        public string $cardLayout,
        public ?string $accountId,
        public string $credentials,
        public ?string $signingSecret,
    ) {
    }

    /** Onsite with an account ID: the payment form shows on the checkout Complete step. */
    public function inline(): bool
    {
        return $this->onsite && null !== $this->accountId;
    }

    public function testMode(): bool
    {
        return str_starts_with($this->apiKey, 'pk_test_') || str_starts_with($this->apiKey, 'mk_test_');
    }

    public function transactionType(): string
    {
        return $this->authorize ? PaymentTransactionType::AUTH : PaymentTransactionType::SALE;
    }

    public static function isMonei(?PaymentMethodInterface $paymentMethod): bool
    {
        return self::FACTORY_NAME === $paymentMethod?->getGatewayConfig()?->getFactoryName();
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidGatewayConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $credentials = $config[self::CREDENTIALS] ?? self::MANUAL;
        if (!\in_array($credentials, [self::MANUAL, self::CONNECTED_LIVE, self::CONNECTED_TEST], true)) {
            throw new InvalidGatewayConfigurationException(self::CREDENTIALS, 'monei.gateway_configuration.credentials.invalid', 'Choose a MONEI credential source.');
        }

        $apiKey = $config[self::API_KEY] ?? null;
        $apiKey = \is_string($apiKey) ? trim($apiKey) : '';
        $signingSecret = null;
        if (self::MANUAL === $credentials) {
            if ('' === $apiKey) {
                throw new InvalidGatewayConfigurationException(self::API_KEY, 'monei.gateway_configuration.api_key.missing', 'The MONEI API key is missing.');
            }
            if (1 !== preg_match('/^pk_(test|live)_\S+$/D', $apiKey)) {
                throw new InvalidGatewayConfigurationException(self::API_KEY, 'monei.gateway_configuration.api_key.invalid', 'The MONEI API key must start with pk_test_ or pk_live_.');
            }
        } else {
            $signingSecret = $config[self::SIGNING_SECRET] ?? null;
            if (!\is_string($signingSecret) || !self::validConnectedKeyPair(self::CONNECTED_LIVE === $credentials, $apiKey, $signingSecret)) {
                throw self::notConnected($credentials);
            }
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

        // Read leniently: a refused config makes bound payments unreachable, and a cosmetic key must not block capture or refund.
        $cardLayout = self::CARD_LAYOUT_SINGLE === ($config[self::CARD_LAYOUT] ?? null) ? self::CARD_LAYOUT_SINGLE : self::CARD_LAYOUT_SPLIT;

        // Lenient for the same reason; a non-UUID value (such as a pasted API key) never reaches a page.
        $accountId = $config[self::ACCOUNT_ID] ?? null;
        $accountId = \is_string($accountId) ? trim($accountId) : '';
        $accountId = 1 === preg_match(self::ACCOUNT_ID_PATTERN, $accountId) ? $accountId : null;

        if (self::MANUAL !== $credentials && null === $accountId) {
            throw self::notConnected($credentials);
        }

        return new self($apiKey, $method, $onsite, $authorize, $orderIdPrefix, $cardLayout, $accountId, $credentials, $signingSecret);
    }

    public static function validConnectedKeyPair(bool $live, string $apiKey, string $signingSecret): bool
    {
        return 1 === preg_match($live ? '/^mk_live_\S+$/D' : '/^mk_test_\S+$/D', $apiKey)
            && 1 === preg_match('/^mksec_\S+$/D', $signingSecret);
    }

    private static function notConnected(string $credentials): InvalidGatewayConfigurationException
    {
        $mode = self::CONNECTED_TEST === $credentials ? 'test' : 'live';

        return new InvalidGatewayConfigurationException(
            self::CREDENTIALS,
            'monei.gateway_configuration.credentials.connect_'.$mode,
            \sprintf('Connect %s with MONEI first.', ucfirst($mode)),
        );
    }
}
