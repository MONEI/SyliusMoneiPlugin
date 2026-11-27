<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\ApplePay;

use Monei\SyliusPlugin\Gateway\GatewayConfigurationProvider;
use Monei\SyliusPlugin\Gateway\InvalidGatewayConfigurationException;
use Monei\SyliusPlugin\Http\Flash;
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
        private ApplePayDomainRegistrar $registrar,
        private RequestStack $requestStack,
        private GatewayConfigurationProvider $gatewayConfigurations,
    ) {
    }

    public function __invoke(GenericEvent $event): void
    {
        $paymentMethod = $event->getSubject();
        if (!$paymentMethod instanceof PaymentMethodInterface || !ApplePayDomainRegistrar::supports($paymentMethod)) {
            return;
        }

        try {
            $configuration = $this->gatewayConfigurations->forPaymentMethod($paymentMethod);
        } catch (InvalidGatewayConfigurationException) {
            Flash::add($this->requestStack, 'warning', 'monei.apple_pay.credentials_unavailable');

            return;
        }

        foreach ($this->registrar->register($paymentMethod, $configuration->apiKey) as $domain => $reason) {
            Flash::add($this->requestStack, 'warning', [
                'message' => 'monei.apple_pay.domain_not_registered',
                'parameters' => ['%domain%' => $domain, '%reason%' => $reason],
            ]);
        }
    }
}
