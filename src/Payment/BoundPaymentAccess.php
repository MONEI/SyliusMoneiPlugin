<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Monei\ApiException;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Psr\Log\LoggerInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;

/**
 * Fetches the MONEI payment bound to a Sylius payment with the API key of the payment
 * method that created it (`details.monei.payment_method`), not the payment's current one.
 */
final readonly class BoundPaymentAccess
{
    private const UNREACHABLE_CODES = [401, 403, 404];

    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
        private MoneiApiFactory $apiFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return BoundPayment|null null when no MONEI payment is bound
     *
     * @throws UnreachableBoundPaymentException when the method is gone, its config is invalid, or MONEI answers 401, 403 or 404
     * @throws ApiException                     on any other MONEI error (5xx, timeout)
     */
    public function fetch(PaymentInterface $payment): ?BoundPayment
    {
        $moneiPaymentId = MoneiDetails::paymentId($payment);
        if (null === $moneiPaymentId) {
            return null;
        }

        $code = MoneiDetails::paymentMethodCode($payment);
        $context = ['monei_payment' => $moneiPaymentId, 'payment' => $payment->getId(), 'payment_method' => $code];

        $paymentMethod = null === $code ? null : $this->paymentMethodRepository->findOneBy(['code' => $code]);
        if (!$paymentMethod instanceof PaymentMethodInterface) {
            throw $this->unreachable('payment method {payment_method} no longer exists', $context);
        }

        try {
            $api = $this->apiFactory->forPaymentMethod($paymentMethod);
        } catch (\InvalidArgumentException $e) {
            throw $this->unreachable('payment method {payment_method} has no valid MONEI configuration', $context + ['reason' => $e->getMessage()]);
        }

        try {
            return new BoundPayment($api->getPayment($moneiPaymentId), $api);
        } catch (ApiException $e) {
            if (\in_array($e->getCode(), self::UNREACHABLE_CODES, true)) {
                throw $this->unreachable('MONEI answered {code} with the API key of payment method {payment_method}', $context + ['code' => $e->getCode()]);
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $context */
    private function unreachable(string $reason, array $context): UnreachableBoundPaymentException
    {
        // The old payment may still be payable in another MONEI account.
        $this->logger->critical('MONEI payment {monei_payment} bound to Sylius payment {payment} cannot be fetched: '.$reason.'. Check it in the MONEI dashboard of that account.', $context);

        return new UnreachableBoundPaymentException();
    }
}
