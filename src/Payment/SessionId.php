<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * Binds a monei.js token made on the checkout Complete page to the MONEI payment of the
 * same order: MONEI refuses the token when the payment carries another session ID.
 */
final readonly class SessionId
{
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

    /** The sessionStorage key under which the Complete page leaves its token for the pay page. */
    public function entryKey(OrderInterface $order): string
    {
        return 'monei.inline.'.$this->forOrder($order);
    }
}
