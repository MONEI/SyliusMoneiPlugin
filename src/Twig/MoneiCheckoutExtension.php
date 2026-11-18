<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Twig;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\InvalidGatewayConfigurationException;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Scalars only: the gateway configuration holds the API key. */
final class MoneiCheckoutExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('monei_method_logos', $this->methodLogos(...)),
        ];
    }

    /**
     * Null for a non-MONEI method and for a broken config: the Payment step and the admin
     * list render every method, so one bad config must not break the page.
     *
     * @return array{method: string, logos: list<string>, test_mode: bool}|null
     */
    public function methodLogos(PaymentMethodInterface $paymentMethod): ?array
    {
        if (!GatewayConfiguration::isMonei($paymentMethod)) {
            return null;
        }

        try {
            $config = GatewayConfiguration::fromPaymentMethod($paymentMethod);
        } catch (InvalidGatewayConfigurationException) {
            return null;
        }

        return [
            'method' => $config->method->value,
            'logos' => $config->method->logos(),
            'test_mode' => str_starts_with($config->apiKey, 'pk_test_'),
        ];
    }
}
