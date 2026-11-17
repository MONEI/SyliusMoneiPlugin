<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Payment;

use Doctrine\DBAL\Exception\DriverException;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Sylius\Component\Core\Model\PaymentInterface;

final class PaymentLockTest extends MoneiTestCase
{
    /** MySQL ER_LOCK_NOWAIT: the row is locked by another transaction. */
    private const ER_LOCK_NOWAIT = 3572;

    /**
     * A lock taken after the transaction's snapshot must still see the state another
     * request committed meanwhile (a lock followed by a plain refresh reads the old
     * snapshot), and must block the row for others until the transaction ends.
     */
    public function testAcquireSeesTheStateCommittedAfterTheSnapshotAndHoldsTheRow(): void
    {
        $payment = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'))->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $connection = $this->entityManager()->getConnection();

        $connection->beginTransaction();
        try {
            self::assertSame('new', $connection->fetchOne('SELECT state FROM sylius_payment WHERE id = ?', [$payment->getId()]));
            $this->secondConnection()->executeStatement("UPDATE sylius_payment SET state = 'failed' WHERE id = ?", [$payment->getId()]);

            self::assertTrue($this->lock()->acquire($payment));
            self::assertSame('failed', $payment->getState());

            try {
                $this->secondConnection()->fetchOne('SELECT id FROM sylius_payment WHERE id = ? FOR UPDATE NOWAIT', [$payment->getId()]);
                self::fail('The payment row is not locked.');
            } catch (DriverException $exception) {
                self::assertSame(self::ER_LOCK_NOWAIT, $exception->getCode());
            }
        } finally {
            $connection->rollBack();
        }
    }

    /** The lock must never open a transaction that nobody commits (console, admin API). */
    public function testAcquireWithoutATransactionTakesNoLock(): void
    {
        $payment = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'))->getLastPayment();
        \assert($payment instanceof PaymentInterface);

        self::assertFalse($this->lock()->acquire($payment));
        self::assertSame(0, $this->entityManager()->getConnection()->getTransactionNestingLevel());
    }

    private function lock(): PaymentLock
    {
        $lock = self::getContainer()->get('monei.test.payment_lock');
        \assert($lock instanceof PaymentLock);

        return $lock;
    }
}
