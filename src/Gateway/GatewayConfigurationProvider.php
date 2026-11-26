<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Gateway;

use Monei\SyliusPlugin\Connect\ConnectionStore;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final readonly class GatewayConfigurationProvider
{
    public function __construct(private ConnectionStore $connections)
    {
    }

    public function forPaymentMethod(PaymentMethodInterface $paymentMethod): GatewayConfiguration
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (GatewayConfiguration::FACTORY_NAME !== $gatewayConfig?->getFactoryName()) {
            throw new \InvalidArgumentException(\sprintf('Payment method "%s" is not a MONEI payment method.', (string) $paymentMethod->getCode()));
        }

        return $this->resolve($gatewayConfig->getConfig());
    }

    /** @param array<array-key, mixed> $config */
    public function resolve(array $config): GatewayConfiguration
    {
        $credentials = $config[GatewayConfiguration::CREDENTIALS] ?? GatewayConfiguration::MANUAL;
        if (\in_array($credentials, [GatewayConfiguration::CONNECTED_LIVE, GatewayConfiguration::CONNECTED_TEST], true)) {
            $connection = $this->connections->read();
            $test = GatewayConfiguration::CONNECTED_TEST === $credentials;
            $config[GatewayConfiguration::API_KEY] = $test ? $connection->testApiKey : $connection->liveApiKey;
            $config[GatewayConfiguration::ACCOUNT_ID] = $test ? $connection->testAccountId : $connection->liveAccountId;
            $config[GatewayConfiguration::SIGNING_SECRET] = $test ? $connection->testSigningSecret : $connection->liveSigningSecret;
        }

        return GatewayConfiguration::fromArray($config);
    }
}
