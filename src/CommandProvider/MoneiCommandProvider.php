<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\CommandProvider;

use Monei\SyliusPlugin\Command\CapturePaymentRequest;
use Monei\SyliusPlugin\Command\NotifyPaymentRequest;
use Monei\SyliusPlugin\Command\StatusPaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Bundle\PaymentBundle\Exception\PaymentRequestNotSupportedException;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * The commands do not implement PaymentRequestHashAwareInterface, so a store that makes
 * the `payment_request` transport async still runs them in the request: the pay command
 * needs the buyer's IP and User-Agent and must return the MONEI redirect URL.
 */
final class MoneiCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return \in_array($paymentRequest->getAction(), [
            PaymentRequestInterface::ACTION_CAPTURE,
            PaymentRequestInterface::ACTION_AUTHORIZE,
            PaymentRequestInterface::ACTION_STATUS,
            PaymentRequestInterface::ACTION_NOTIFY,
        ], true);
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        $hash = (string) $paymentRequest->getId();

        return match ($paymentRequest->getAction()) {
            PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE => new CapturePaymentRequest($hash),
            PaymentRequestInterface::ACTION_STATUS => new StatusPaymentRequest($hash),
            PaymentRequestInterface::ACTION_NOTIFY => new NotifyPaymentRequest($hash),
            default => throw new PaymentRequestNotSupportedException(\sprintf('MONEI does not support the "%s" payment request action.', $paymentRequest->getAction())),
        };
    }
}
