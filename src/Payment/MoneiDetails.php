<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * The MONEI part of a Sylius payment's details: `details.monei` = `{id, payment_method,
 * transaction_type, monei_method, order_id}`. Only `id` binds the Sylius payment to a MONEI payment;
 * `payment_method` is the code of the Sylius payment method whose API key created it,
 * `transaction_type` the SALE or AUTH it was created with (a MONEI payment does not tell it),
 * and `monei_method` the MONEI method that method was configured with.
 */
final class MoneiDetails
{
    private const KEY = 'monei';

    public static function paymentId(PaymentInterface $payment): ?string
    {
        $id = self::read($payment)['id'] ?? null;

        return \is_string($id) && '' !== $id ? $id : null;
    }

    public static function paymentMethodCode(PaymentInterface $payment): ?string
    {
        $code = self::read($payment)['payment_method'] ?? null;

        return \is_string($code) ? $code : null;
    }

    public static function transactionType(PaymentInterface $payment): ?string
    {
        $type = self::read($payment)['transaction_type'] ?? null;

        return \is_string($type) ? $type : null;
    }

    public static function moneiMethod(PaymentInterface $payment): ?string
    {
        $method = self::read($payment)['monei_method'] ?? null;

        return \is_string($method) ? $method : null;
    }

    public static function orderId(PaymentInterface $payment): ?string
    {
        $orderId = self::read($payment)['order_id'] ?? null;

        return \is_string($orderId) && '' !== $orderId ? $orderId : null;
    }

    public static function bind(PaymentInterface $payment, string $moneiPaymentId, string $paymentMethodCode, string $transactionType, string $moneiMethod, ?string $orderId = null): void
    {
        $details = $payment->getDetails();
        $details[self::KEY] = [
            'id' => $moneiPaymentId,
            'payment_method' => $paymentMethodCode,
            'transaction_type' => $transactionType,
            'monei_method' => $moneiMethod,
        ];
        if (null !== $orderId) {
            $details[self::KEY]['order_id'] = $orderId;
        }
        $payment->setDetails($details);
    }

    /** @return array<string, mixed> */
    private static function read(PaymentInterface $payment): array
    {
        $monei = $payment->getDetails()[self::KEY] ?? [];

        return \is_array($monei) ? $monei : [];
    }
}
