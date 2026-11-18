<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Binds a monei.js token made on the checkout Complete page to the MONEI payment of the
 * same order: MONEI refuses the token when the payment carries another session ID.
 */
final readonly class SessionId
{
    public const ENTRY_KEY_PREFIX = 'monei.inline.';

    public function __construct(private string $secret)
    {
    }

    /** 40 hex characters, within MONEI's `^[a-zA-Z0-9_-]{1,40}$`. */
    public function forOrder(OrderInterface $order): string
    {
        $id = $order->getId();
        if (null === $id) {
            throw new \LogicException('The order has no ID yet.');
        }

        return substr(hash_hmac('sha256', 'monei-session|'.$id, $this->secret), 0, 40);
    }

    /**
     * The sessionStorage key under which the Complete page leaves its token for the pay page.
     * A failed MONEI create fails the payment, and Sylius adds a new one: a pay again on that
     * new payment must not find the token.
     */
    public function entryKey(PaymentInterface $payment): string
    {
        $order = $payment->getOrder();
        $id = $payment->getId();
        if (null === $order || null === $id) {
            throw new \LogicException('The payment has no ID or no order yet.');
        }

        return self::ENTRY_KEY_PREFIX.$this->forOrder($order).'.'.$id;
    }
}
