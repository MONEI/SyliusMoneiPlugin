<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\ApplePay;

use Monei\ApiException;
use Monei\SyliusPlugin\Api\MoneiApi;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\MoneiMethod;
use Monei\SyliusPlugin\Http\Flash;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Registers the hostnames of an enabled Apple Pay / Google Pay payment method's channels
 * with MONEI after the admin saves it. A refusal never blocks the save: it shows a warning.
 */
final readonly class RegisterDomainOnSaveListener
{
    public function __construct(
        private MoneiApiFactory $apiFactory,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenericEvent $event): void
    {
        $paymentMethod = $event->getSubject();
        if (!$paymentMethod instanceof PaymentMethodInterface || !$paymentMethod->isEnabled() || !GatewayConfiguration::isMonei($paymentMethod)) {
            return;
        }

        $configuration = GatewayConfiguration::fromPaymentMethod($paymentMethod);
        if (MoneiMethod::AppleGooglePay !== $configuration->method) {
            return;
        }

        $api = $this->apiFactory->forApiKey($configuration->apiKey);
        foreach ($this->domains($paymentMethod) as $domain) {
            try {
                $api->registerApplePayDomain($domain);
            } catch (ApiException $e) {
                $reason = MoneiApi::reason($e);
                $this->logger->warning('MONEI did not register the Apple Pay domain {domain}: {reason}', [
                    'domain' => $domain,
                    'reason' => $reason,
                    'payment_method' => $paymentMethod->getCode(),
                ]);
                Flash::add($this->requestStack, 'warning', [
                    'message' => 'monei.apple_pay.domain_not_registered',
                    'parameters' => ['%domain%' => $domain, '%reason%' => $reason],
                ]);
            }
        }
    }

    /** @return list<string> */
    private function domains(PaymentMethodInterface $paymentMethod): array
    {
        $requestHost = $this->requestStack->getMainRequest()?->getHost();
        $domains = [];
        foreach ($paymentMethod->getChannels() as $channel) {
            \assert($channel instanceof ChannelInterface);
            $hostname = $channel->getHostname();
            $domain = null !== $hostname && '' !== $hostname ? $hostname : $requestHost;
            if (null !== $domain && '' !== $domain) {
                $domains[$domain] = $domain;
            }
        }

        return array_values($domains);
    }
}
