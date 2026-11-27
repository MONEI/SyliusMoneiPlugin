<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\ApplePay;

use Monei\ApiException;
use Monei\SyliusPlugin\Api\MoneiApi;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\MoneiMethod;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class ApplePayDomainRegistrar
{
    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethods */
    public function __construct(
        private MoneiApiFactory $apiFactory,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private PaymentMethodRepositoryInterface $paymentMethods,
    ) {
    }

    public static function supports(PaymentMethodInterface $method): bool
    {
        return $method->isEnabled() && GatewayConfiguration::isMonei($method)
            && MoneiMethod::AppleGooglePay->value === ($method->getGatewayConfig()?->getConfig()[GatewayConfiguration::METHOD] ?? null);
    }

    public function registerConnected(string $mode, string $apiKey): bool
    {
        $source = 'live' === $mode ? GatewayConfiguration::CONNECTED_LIVE : GatewayConfiguration::CONNECTED_TEST;
        $registered = true;
        foreach ($this->paymentMethods->findBy(['enabled' => true]) as $method) {
            if (self::supports($method) && $source === ($method->getGatewayConfig()?->getConfig()[GatewayConfiguration::CREDENTIALS] ?? null)) {
                if ([] !== $this->register($method, $apiKey)) {
                    $registered = false;
                }
            }
        }

        return $registered;
    }

    /** @return array<string, string> Domain registration errors. */
    public function register(PaymentMethodInterface $method, string $apiKey): array
    {
        $errors = [];
        $api = $this->apiFactory->forApiKey($apiKey);
        $requestHost = $this->requestStack->getMainRequest()?->getHost();
        $domains = [];
        foreach ($method->getChannels() as $channel) {
            \assert($channel instanceof ChannelInterface);
            $hostname = $channel->getHostname();
            $domain = null !== $hostname && '' !== $hostname ? $hostname : $requestHost;
            if (null !== $domain && '' !== $domain) {
                $domains[$domain] = $domain;
            }
        }

        foreach ($domains as $domain) {
            try {
                $api->registerApplePayDomain($domain);
            } catch (ApiException $e) {
                $errors[$domain] = MoneiApi::reason($e);
                $this->logger->warning('MONEI did not register the Apple Pay domain {domain} (status {status})', [
                    'domain' => $domain,
                    'status' => $e->getCode(),
                    'payment_method' => $method->getCode(),
                ]);
            }
        }

        return $errors;
    }
}
