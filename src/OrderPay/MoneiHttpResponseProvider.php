<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\OrderPay;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Payment\SessionId;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Sends the buyer to MONEI (hosted) or renders the monei.js page (onsite) after the
 * capture handler ran. Without an answer from here, Sylius sends the buyer to the
 * after-pay URL. The first parameter is a RequestConfiguration on Sylius 2.2 and a
 * Request on 2.3.
 */
final readonly class MoneiHttpResponseProvider implements HttpResponseProviderInterface
{
    public function __construct(
        private Environment $twig,
        private UrlProviderInterface $afterPayUrlProvider,
        private SessionId $sessionId,
    ) {
    }

    public function supports(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): bool
    {
        return \in_array($paymentRequest->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE], true)
            && PaymentRequestInterface::STATE_PROCESSING === $paymentRequest->getState()
            && PaymentInterface::STATE_NEW === $paymentRequest->getPayment()->getState()
            && (null !== self::redirectUrl($paymentRequest) || null !== self::onsitePaymentId($paymentRequest));
    }

    public function getResponse(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): Response
    {
        $moneiPaymentId = self::onsitePaymentId($paymentRequest);
        if (null === $moneiPaymentId) {
            return new RedirectResponse((string) self::redirectUrl($paymentRequest), Response::HTTP_SEE_OTHER);
        }

        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException('A Sylius core payment is expected.');
        }
        $order = $payment->getOrder();
        if (!$order instanceof OrderInterface) {
            throw new \LogicException('The payment has no order.');
        }
        $configuration = GatewayConfiguration::fromPaymentMethod($paymentRequest->getMethod());
        $inline = $configuration->inline();

        // Scalars only: the gateway configuration holds the API key.
        return new Response($this->twig->render('@MoneiSyliusPlugin/shop/order_pay/onsite.html.twig', [
            'order' => $order,
            'monei' => [
                'payment_id' => $moneiPaymentId,
                'component' => $configuration->method->onsiteComponent()
                    ?? throw new \LogicException(\sprintf('MONEI %s has no onsite mode.', $configuration->method->value)),
                // monei.js loads PayPal for a capture unless it gets AUTH, and a capture cannot pay an AUTH payment.
                'transaction_type' => $configuration->transactionType(),
                'after_pay_url' => $this->afterPayUrlProvider->getUrl($paymentRequest),
                'card_layout' => $configuration->cardLayout,
                // The key of the browser entry the Complete page left with its token.
                'session_id' => $inline ? $this->sessionId->forOrder($order) : null,
                'method_code' => $inline ? (string) $paymentRequest->getMethod()->getCode() : null,
            ],
        ]));
    }

    private static function redirectUrl(PaymentRequestInterface $paymentRequest): ?string
    {
        $url = $paymentRequest->getResponseData()['redirect_url'] ?? null;

        return \is_string($url) && '' !== $url ? $url : null;
    }

    private static function onsitePaymentId(PaymentRequestInterface $paymentRequest): ?string
    {
        $responseData = $paymentRequest->getResponseData();
        $id = $responseData['monei_payment_id'] ?? null;

        return true === ($responseData['onsite'] ?? null) && \is_string($id) && '' !== $id ? $id : null;
    }
}
