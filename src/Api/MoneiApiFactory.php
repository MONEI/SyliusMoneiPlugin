<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Api;

use Monei\Api\ApplePayDomainApi;
use Monei\Api\PaymentMethodsApi;
use Monei\Api\PaymentsApi;
use Monei\Configuration;
use Monei\Internal\GuzzleHttp\ClientInterface;
use Monei\SyliusPlugin\Gateway\GatewayConfigurationProvider;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final readonly class MoneiApiFactory
{
    public function __construct(
        private ClientInterface $httpClient,
        private string $userAgent,
        // Short timeout: the payment methods call runs while the checkout page renders.
        private ClientInterface $renderHttpClient,
        private GatewayConfigurationProvider $gatewayConfigurations,
    ) {
    }

    public function forPaymentMethod(PaymentMethodInterface $paymentMethod): MoneiApi
    {
        return $this->forApiKey($this->gatewayConfigurations->forPaymentMethod($paymentMethod)->apiKey);
    }

    public function forApiKey(string $apiKey): MoneiApi
    {
        // A new Configuration per key: MoneiClient without one writes the key into a
        // process-wide singleton, so two accounts in one process would share a key.
        $config = new Configuration();
        $config->setApiKey('Authorization', $apiKey);
        $config->setUserAgent($this->userAgent);

        return new MoneiApi(
            new PaymentsApi($this->httpClient, $config),
            new ApplePayDomainApi($this->httpClient, $config),
            new PaymentMethodsApi($this->renderHttpClient, $config),
            new PaymentMethodsApi($this->httpClient, $config),
            $this->httpClient,
        );
    }
}
