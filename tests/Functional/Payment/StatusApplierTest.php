<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Payment;

use Monei\Model\Payment as MoneiPayment;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Monolog\Level;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class StatusApplierTest extends MoneiTestCase
{
    private const BOUND_ID = 'pay_bound';

    private PaymentInterface $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $payment = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'))->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        MoneiDetails::bind($payment, self::BOUND_ID, 'MONEI_CARD', 'SALE');
        $this->entityManager()->flush();
        $this->payment = $payment;
    }

    public function testSucceededCompletesThePaymentAndPaysTheOrder(): void
    {
        $this->apply('SUCCEEDED');

        self::assertSame('completed', $this->storedPaymentState());
        self::assertSame('paid', $this->secondConnection()->fetchOne(
            'SELECT payment_state FROM sylius_order WHERE id = ?',
            [$this->payment->getOrder()?->getId()],
        ));
    }

    public function testAuthorizedAuthorizesThePayment(): void
    {
        $this->apply('AUTHORIZED');

        self::assertSame('authorized', $this->storedPaymentState());
    }

    public function testExpiredHoldFailsThePayment(): void
    {
        $this->transition(PaymentTransitions::TRANSITION_AUTHORIZE);

        $this->apply('EXPIRED');

        self::assertSame('failed', $this->storedPaymentState());
    }

    public function testRefundedOnANewPaymentCompletesAndRefundsIt(): void
    {
        $this->apply('REFUNDED');

        self::assertSame('refunded', $this->storedPaymentState());
    }

    public function testPartiallyRefundedCompletesThePaymentWithoutRefundingIt(): void
    {
        $this->apply('PARTIALLY_REFUNDED');

        self::assertSame('completed', $this->storedPaymentState());
    }

    public function testCanceledCancelsThePayment(): void
    {
        $this->apply('CANCELED');

        self::assertSame('cancelled', $this->storedPaymentState());
    }

    public function testSucceededOnACancelledPaymentChangesNothingAndAlerts(): void
    {
        $this->transition(PaymentTransitions::TRANSITION_CANCEL);

        $this->apply('SUCCEEDED');

        self::assertSame('cancelled', $this->storedPaymentState());
        self::assertCriticalLogged();
    }

    public function testAmountOneCentLowerChangesNothingAndAlerts(): void
    {
        $this->apply('SUCCEEDED', amount: 9999);

        self::assertSame('new', $this->storedPaymentState());
        self::assertCriticalLogged();
    }

    public function testOtherCurrencyChangesNothingAndAlerts(): void
    {
        $this->apply('SUCCEEDED', currency: 'USD');

        self::assertSame('new', $this->storedPaymentState());
        self::assertCriticalLogged();
    }

    public function testSucceededPaymentThatIsNotTheBoundOneChangesNothingAndAlerts(): void
    {
        $this->apply('SUCCEEDED', id: 'pay_orphan');

        self::assertSame('new', $this->storedPaymentState());
        self::assertCriticalLogged();
    }

    private function apply(string $status, string $id = self::BOUND_ID, int $amount = 10000, string $currency = 'EUR'): void
    {
        $applier = self::getContainer()->get('monei.test.status_applier');
        \assert($applier instanceof StatusApplier);

        $applier->apply($this->payment, new MoneiPayment([
            'id' => $id,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
        ]));
        $this->entityManager()->flush();
    }

    private function transition(string $transition): void
    {
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        \assert($stateMachine instanceof StateMachineInterface);
        $stateMachine->apply($this->payment, PaymentTransitions::GRAPH, $transition);
        $this->entityManager()->flush();
    }

    /** Read by id: a failed or cancelled payment makes Sylius add a new payment to the order. */
    private function storedPaymentState(): string
    {
        $state = $this->secondConnection()->fetchOne('SELECT state FROM sylius_payment WHERE id = ?', [$this->payment->getId()]);
        \assert(\is_string($state));

        return $state;
    }

    private function assertCriticalLogged(): void
    {
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }
}
