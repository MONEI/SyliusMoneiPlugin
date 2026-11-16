<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * The MONEI part of a Sylius payment's details: `details.monei`. It binds the Sylius
 * payment to one MONEI payment; the status stored here is informational only.
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

    public static function bind(PaymentInterface $payment, string $moneiPaymentId, string $paymentMethodCode, ?string $status): void
    {
        $details = $payment->getDetails();
        $details[self::KEY] = [
            'id' => $moneiPaymentId,
            'payment_method' => $paymentMethodCode,
            'status' => $status,
        ];
        $payment->setDetails($details);
    }

    public static function updateStatus(PaymentInterface $payment, ?string $status): void
    {
        $details = $payment->getDetails();
        $monei = self::read($payment);
        $monei['status'] = $status;
        $details[self::KEY] = $monei;
        $payment->setDetails($details);
    }

    /** @return array<string, mixed> */
    private static function read(PaymentInterface $payment): array
    {
        $monei = $payment->getDetails()[self::KEY] ?? [];

        return is_array($monei) ? $monei : [];
    }
}
