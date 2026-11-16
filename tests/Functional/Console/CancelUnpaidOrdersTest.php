<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Console;

use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Monolog\Level;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `sylius:cancel-unpaid-orders` with MONEI payments. The command loops until no expired
 * unpaid order is left, so every MONEI answer must let it end.
 */
final class CancelUnpaidOrdersTest extends MoneiTestCase
{
    private PaymentMethodInterface $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = $this->createMoneiPaymentMethod('MONEI_CARD');
    }

    public function testAPaidOrderIsKeptAndRecordedAndAPendingOneIsCancelledAtMonei(): void
    {
        $paid = $this->expiredOrder('pay_1');
        $pending = $this->expiredOrder('pay_2');
        MoneiMockHandler::appendFor('GET /payments/pay_1', self::moneiPayment('pay_1', 'SUCCEEDED'));
        MoneiMockHandler::appendFor('GET /payments/pay_2', self::moneiPayment('pay_2', 'PENDING'));
        MoneiMockHandler::appendFor('POST /payments/pay_2/cancel', self::moneiPayment('pay_2', 'CANCELED'));

        $this->runCommand();

        // Paid and with nothing to ship, the order is fulfilled, not cancelled.
        self::assertSame(['fulfilled', 'completed'], [$this->orderState($paid), $this->paymentState($paid)]);
        self::assertSame('cancelled', $this->orderState($pending));
        self::assertContains('POST /payments/pay_2/cancel', $this->requestKeys());
    }

    public function testAPendingPaymentPaidWhileItsCancelWasRefusedKeepsTheOrder(): void
    {
        $order = $this->expiredOrder('pay_1');
        MoneiMockHandler::appendFor('GET /payments/pay_1', self::moneiPayment('pay_1', 'PENDING'), self::moneiPayment('pay_1', 'SUCCEEDED'));
        MoneiMockHandler::appendFor('POST /payments/pay_1/cancel', self::error(400, 'The payment cannot be canceled'));

        $this->runCommand();

        self::assertSame(['fulfilled', 'completed'], [$this->orderState($order), $this->paymentState($order)]);
    }

    public function testAPendingPaymentThatCannotBeCancelledLetsTheOrderBeCancelled(): void
    {
        $order = $this->expiredOrder('pay_1');
        MoneiMockHandler::appendFor('GET /payments/pay_1', self::moneiPayment('pay_1', 'PENDING'), self::moneiPayment('pay_1', 'PENDING'));
        MoneiMockHandler::appendFor('POST /payments/pay_1/cancel', self::error(400, 'The payment cannot be canceled'));

        $this->runCommand();

        self::assertSame('cancelled', $this->orderState($order));
        self::assertContains(Level::Warning, array_column($this->moneiLogs(), 'level'));
    }

    /** The payment cannot be recorded as paid, so refusing would make the command find the order again forever. */
    public function testASucceededPaymentWithAnAmountMismatchLetsTheOrderBeCancelledWithACriticalLog(): void
    {
        $order = $this->expiredOrder('pay_1');
        MoneiMockHandler::appendFor('GET /payments/pay_1', self::moneiPayment('pay_1', 'SUCCEEDED', amount: self::PRODUCT_PRICE - 1));

        $this->runCommand();

        self::assertSame('cancelled', $this->orderState($order));
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }

    public function testAMonei500OnTheGuardFetchLetsTheOrderBeCancelledWithAnError(): void
    {
        $order = $this->expiredOrder('pay_1');
        MoneiMockHandler::appendFor('GET /payments/pay_1', self::error(500, 'Internal server error'));

        $this->runCommand();

        self::assertSame('cancelled', $this->orderState($order));
        self::assertContains(Level::Error, array_column($this->moneiLogs(), 'level'));
    }

    private function expiredOrder(string $moneiPaymentId): OrderInterface
    {
        $order = $this->placeOrder($this->method);
        $this->bindPayment($order, $moneiPaymentId, 'MONEI_CARD');
        $this->secondConnection()->executeStatement('UPDATE sylius_order SET checkout_completed_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $order->getId()]);

        return $order;
    }

    private function runCommand(): void
    {
        $kernel = self::$kernel;
        \assert(null !== $kernel);
        $tester = new CommandTester((new Application($kernel))->find('sylius:cancel-unpaid-orders'));

        self::assertSame(0, $tester->execute([]));
    }
}
