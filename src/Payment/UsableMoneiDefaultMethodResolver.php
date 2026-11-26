<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Sylius\Component\Payment\Exception\UnresolvedDefaultPaymentMethodException;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\DefaultPaymentMethodResolverInterface;

final readonly class UsableMoneiDefaultMethodResolver implements DefaultPaymentMethodResolverInterface
{
    public function __construct(
        private DefaultPaymentMethodResolverInterface $inner,
        private UsableMoneiMethodsResolver $methods,
    ) {
    }

    public function getDefaultPaymentMethod(PaymentInterface $payment): PaymentMethodInterface
    {
        $method = $this->inner->getDefaultPaymentMethod($payment);
        if ($this->methods->isUsable($method)) {
            return $method;
        }

        return $this->methods->getSupportedMethods($payment)[0] ?? throw new UnresolvedDefaultPaymentMethodException();
    }
}
