<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\OrderPay;

use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the buyer to MONEI after the capture handler ran. Without an answer from here,
 * Sylius sends the buyer to the after-pay URL. The first parameter is a
 * RequestConfiguration on Sylius 2.2 and a Request on 2.3.
 */
final class MoneiHttpResponseProvider implements HttpResponseProviderInterface
{
    public function supports(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): bool
    {
        return \in_array($paymentRequest->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE], true)
            && PaymentRequestInterface::STATE_PROCESSING === $paymentRequest->getState()
            && PaymentInterface::STATE_NEW === $paymentRequest->getPayment()->getState()
            && null !== self::redirectUrl($paymentRequest);
    }

    public function getResponse(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): Response
    {
        return new RedirectResponse((string) self::redirectUrl($paymentRequest), Response::HTTP_SEE_OTHER);
    }

    private static function redirectUrl(PaymentRequestInterface $paymentRequest): ?string
    {
        $url = $paymentRequest->getResponseData()['redirect_url'] ?? null;

        return \is_string($url) && '' !== $url ? $url : null;
    }
}
