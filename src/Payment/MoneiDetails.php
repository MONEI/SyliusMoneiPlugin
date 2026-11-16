<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * The MONEI part of a Sylius payment's details: `details.monei` = `{id, payment_method}`.
 * Only `id` binds the Sylius payment to a MONEI payment; `payment_method` is the code of
 * the Sylius payment method whose API key created it.
 */
final class MoneiDetails
{
    private const KEY = 'monei';

    public static function paymentId(PaymentInterface $payment): ?string
    {
        $id = self::read($payment)['id'] ?? null;

        return is_string($id) && '' !== $id ? $id : null;
    }

    public static function paymentMethodCode(PaymentInterface $payment): ?string
    {
        $code = self::read($payment)['payment_method'] ?? null;

        return is_string($code) ? $code : null;
    }

    public static function bind(PaymentInterface $payment, string $moneiPaymentId, string $paymentMethodCode): void
    {
        $details = $payment->getDetails();
        $details[self::KEY] = [
            'id' => $moneiPaymentId,
            'payment_method' => $paymentMethodCode,
        ];
        $payment->setDetails($details);
    }

    /** @return array<string, mixed> */
    private static function read(PaymentInterface $payment): array
    {
        $monei = $payment->getDetails()[self::KEY] ?? [];

        return is_array($monei) ? $monei : [];
    }
}
