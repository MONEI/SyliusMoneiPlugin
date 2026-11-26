<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Callback;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface as CorePaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Finds the Sylius payment of a MONEI callback on `/payment-methods/{code}?payment=<id>`,
 * the callback URL the plugin sets on every MONEI payment. A payment that now uses another
 * method, or has no bound MONEI payment, still goes to the handler: a paid old MONEI
 * payment must be reported.
 */
final readonly class MoneiNotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    private const PAYMENT_QUERY = 'payment';

    /** @param PaymentRepositoryInterface<CorePaymentInterface> $paymentRepository */
    public function __construct(
        private PaymentRepositoryInterface $paymentRepository,
        private CallbackVerifier $verifier,
        private CallbackQuota $quota,
    ) {
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
        $callback = $this->verifier->forPaymentMethod($request->getContent(), $request->headers->get('MONEI-Signature'), $paymentMethod);

        $paymentId = $request->query->all()[self::PAYMENT_QUERY] ?? null;
        if (!\is_string($paymentId) || 1 !== preg_match('/^\d+$/D', $paymentId)) {
            throw InvalidCallbackException::refused(\sprintf('the callback URL has no "%s" parameter; only the callback URL the plugin sets on each payment is supported', self::PAYMENT_QUERY));
        }

        $payment = $this->paymentRepository->find((int) $paymentId);
        if (!$payment instanceof PaymentInterface) {
            throw InvalidCallbackException::ignored(\sprintf('Sylius payment %s does not exist (MONEI payment %s)', $paymentId, $callback->paymentId));
        }

        if (!$callback->signed) {
            // Only the handler charges attempts, including replays of stored notify requests.
            $this->quota->check((int) $paymentId, $callback->paymentId);
        }
        $callback->apiKeyOrFail();

        return $payment;
    }
}
