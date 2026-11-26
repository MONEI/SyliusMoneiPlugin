<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\GatewayConfigurationProvider;
use Monei\SyliusPlugin\Gateway\InvalidGatewayConfigurationException;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;

final readonly class UsableMoneiMethodsResolver implements PaymentMethodsResolverInterface
{
    public function __construct(
        private PaymentMethodsResolverInterface $inner,
        private GatewayConfigurationProvider $gatewayConfigurations,
    ) {
    }

    public function getSupportedMethods(PaymentInterface $subject): array
    {
        return array_values(array_filter($this->inner->getSupportedMethods($subject), $this->isUsable(...)));
    }

    public function supports(PaymentInterface $subject): bool
    {
        return $this->inner->supports($subject);
    }

    public function isUsable(PaymentMethodInterface $method): bool
    {
        if (!GatewayConfiguration::isMonei($method)) {
            return true;
        }

        try {
            $this->gatewayConfigurations->forPaymentMethod($method);
        } catch (InvalidGatewayConfigurationException) {
            return false;
        }

        return true;
    }
}
