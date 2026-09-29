<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Action;

use Monei\SyliusPlugin\Action\StatusAction;
use Monei\SyliusPlugin\Client\MoneiApiClientInterface;
use Monei\SyliusPlugin\Resolver\PaymentStatusResolver;
use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;

final class StatusActionTest extends TestCase
{
    public function testItMarksNewWhenNoMoneiPaymentId(): void
    {
        $api = $this->createMock(MoneiApiClientInterface::class);
        $resolver = new PaymentStatusResolver();

        $action = new StatusAction($resolver);
        $action->setApi($api);

        $payment = new Payment();
        $payment->setDetails([]);

        $status = new GetHumanStatus($payment);
        $action->execute($status);

        $this->assertTrue($status->isNew());
    }

    /**
     * @dataProvider statusProvider
     */
    public function testItMapsMoneiStatusCorrectly(string $moneiStatus, string $expectedMethod): void
    {
        $api = $this->createMock(MoneiApiClientInterface::class);
        $api->method('getPayment')->willReturn([
            'id' => 'pay_test_123',
            'status' => $moneiStatus,
        ]);

        $resolver = new PaymentStatusResolver();
        $action = new StatusAction($resolver);
        $action->setApi($api);

        $payment = new Payment();
        $payment->setDetails([
            'monei_payment_id' => 'pay_test_123',
        ]);

        $status = new GetHumanStatus($payment);
        $action->execute($status);

        $this->assertTrue(
            $status->{'is'.ucfirst($expectedMethod)}(),
            sprintf('Expected "%s" for MONEI status "%s"', $expectedMethod, $moneiStatus),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function statusProvider(): array
    {
        return [
            'SUCCEEDED' => ['SUCCEEDED', 'captured'],
            'AUTHORIZED' => ['AUTHORIZED', 'authorized'],
            'PENDING' => ['PENDING', 'pending'],
            'FAILED' => ['FAILED', 'failed'],
            'CANCELED' => ['CANCELED', 'canceled'],
            'REFUNDED' => ['REFUNDED', 'refunded'],
        ];
    }

    public function testItFallsBackToCachedStatusOnApiFailure(): void
    {
        $api = $this->createMock(MoneiApiClientInterface::class);
        $api->method('getPayment')->willThrowException(new \RuntimeException('Connection failed'));

        $resolver = new PaymentStatusResolver();
        $action = new StatusAction($resolver);
        $action->setApi($api);

        $payment = new Payment();
        $payment->setDetails([
            'monei_payment_id' => 'pay_test_123',
            'monei_status' => 'SUCCEEDED',
        ]);

        $status = new GetHumanStatus($payment);
        $action->execute($status);

        $this->assertTrue($status->isCaptured());
    }
}
