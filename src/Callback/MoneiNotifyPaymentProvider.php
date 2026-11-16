<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Callback;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Finds the Sylius payment of a MONEI callback on `/payment-methods/{code}?payment=<id>`,
 * the callback URL the plugin sets on every MONEI payment.
 */
final readonly class MoneiNotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    public const PAYMENT_QUERY = 'payment';

    /** @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository */
    public function __construct(private PaymentRepositoryInterface $paymentRepository)
    {
    }

    public static function callbackUrl(UrlGeneratorInterface $urlGenerator, PaymentInterface $payment, PaymentMethodInterface $paymentMethod): string
    {
        return $urlGenerator->generate('sylius_payment_method_notify', [
            'code' => $paymentMethod->getCode(),
            self::PAYMENT_QUERY => $payment->getId(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return GatewayConfiguration::isMonei($paymentMethod);
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        // The signature is checked before anything is loaded.
        $callback = SignedCallback::verify(
            $request->getContent(),
            $request->headers->get('MONEI-Signature'),
            GatewayConfiguration::fromPaymentMethod($paymentMethod)->apiKey,
        );

        $paymentId = $request->query->get(self::PAYMENT_QUERY);
        if (!is_string($paymentId) || 1 !== preg_match('/^\d+$/', $paymentId)) {
            throw InvalidCallbackException::refused(sprintf('the callback URL has no "%s" parameter; only the callback URL the plugin sets on each payment is supported', self::PAYMENT_QUERY));
        }

        $payment = $this->paymentRepository->find($paymentId);
        if (!$payment instanceof PaymentInterface) {
            throw InvalidCallbackException::ignored(sprintf('Sylius payment %s does not exist (MONEI payment %s)', $paymentId, $callback->paymentId));
        }

        if ($payment->getMethod() !== $paymentMethod) {
            throw InvalidCallbackException::ignored(sprintf('Sylius payment %s no longer uses payment method "%s" (MONEI payment %s)', $paymentId, (string) $paymentMethod->getCode(), $callback->paymentId));
        }

        // A payment whose MONEI payment was replaced keeps going: the handler fetches the
        // signed payment and reports it when it holds money.
        if (null === MoneiDetails::paymentId($payment)) {
            throw InvalidCallbackException::ignored(sprintf('Sylius payment %s has no MONEI payment (MONEI payment %s)', $paymentId, $callback->paymentId));
        }

        return $payment;
    }
}
