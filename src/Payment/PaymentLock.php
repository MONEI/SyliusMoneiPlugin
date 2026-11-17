<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * One mutex per Sylius payment, shared by the pay, return, callback and admin paths: a
 * row lock (SELECT ... FOR UPDATE) held until the caller's transaction ends. It never
 * opens a transaction: the payment request bus and Core's ResourceUpdateHandler do.
 */
final readonly class PaymentLock
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Locks the payment row and reloads the payment from it. The locking read sees the
     * state another transaction committed while this one waited, not the transaction's
     * older snapshot. The reload discards unflushed changes to the payment.
     *
     * @return bool false when no transaction is active (console, admin API): no lock is taken
     */
    public function acquire(PaymentInterface $payment): bool
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            return false;
        }

        $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);

        return true;
    }
}
