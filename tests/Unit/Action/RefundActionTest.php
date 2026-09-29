<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Action;

use Monei\SyliusPlugin\Action\RefundAction;
use Monei\SyliusPlugin\Client\MoneiApiClientInterface;
use Payum\Core\Request\Refund;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;

final class RefundActionTest extends TestCase
{
    public function testItRefundsPayment(): void
    {
        $api = $this->createMock(MoneiApiClientInterface::class);
        $api->expects($this->once())
            ->method('refundPayment')
            ->with('pay_test_123', 1500, 'Refund from Sylius admin')
            ->willReturn(['id' => 'pay_test_123', 'status' => 'REFUNDED']);

        $action = new RefundAction();
        $action->setApi($api);

        $payment = new Payment();
        $payment->setDetails([
            'monei_payment_id' => 'pay_test_123',
            'amount' => 1500,
        ]);

        $action->execute(new Refund($payment));

        $details = $payment->getDetails();
        $this->assertSame('REFUNDED', $details['monei_status']);
    }

    public function testItThrowsWhenNoMoneiPaymentId(): void
    {
        $api = $this->createMock(MoneiApiClientInterface::class);

        $action = new RefundAction();
        $action->setApi($api);

        $payment = new Payment();
        $payment->setDetails([]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot refund: no MONEI payment ID found.');

        $action->execute(new Refund($payment));
    }

    public function testItSupportsRefundWithPayment(): void
    {
        $action = new RefundAction();

        $this->assertTrue($action->supports(new Refund(new Payment())));
    }
}
