<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Client;

use Monei\Api\PaymentsApi;
use Monei\Model\Payment;
use Monei\Model\PaymentNextAction;
use Monei\Model\PaymentStatus;
use Monei\SyliusPlugin\Client\MoneiApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Note: SDK calls are tested by swapping the SDK's PaymentsApi for a mock.
 * Integration tests against the MONEI sandbox are recommended for end-to-end validation.
 */
final class MoneiApiClientTest extends TestCase
{
    public function testItExposesApiKey(): void
    {
        $client = new MoneiApiClient('pk_test_abc123', 'acc_test_456');

        $this->assertSame('pk_test_abc123', $client->getApiKey());
    }

    public function testItExposesAccountId(): void
    {
        $client = new MoneiApiClient('pk_test_abc123', 'acc_test_456');

        $this->assertSame('acc_test_456', $client->getAccountId());
    }

    /**
     * NotifyAction and StatusAction sync the Sylius payment state from this
     * call, so it must hit a method the SDK really has and expose the fields
     * they read.
     */
    public function testItFetchesPaymentThroughSdk(): void
    {
        $payments = $this->createMock(PaymentsApi::class);
        $payments->expects($this->once())
            ->method('get')
            ->with('pay_123')
            ->willReturn(new Payment([
                'id' => 'pay_123',
                'status' => PaymentStatus::SUCCEEDED,
                'status_message' => 'Transaction approved',
            ]));

        $client = new MoneiApiClient('pk_test_abc123', 'acc_test_456');
        $sdk = (new \ReflectionProperty($client, 'client'))->getValue($client);
        $sdk->payments = $payments;

        $payment = $client->getPayment('pay_123');

        $this->assertSame('pay_123', $payment['id']);
        $this->assertSame('SUCCEEDED', $payment['status']);
        $this->assertSame('Transaction approved', $payment['statusMessage']);
    }

    /**
     * CaptureAction reads nextAction.redirectUrl to send the customer to MONEI,
     * so nested SDK models must come back as arrays, not stdClass.
     */
    public function testItReturnsNestedModelsAsArrays(): void
    {
        $payments = $this->createMock(PaymentsApi::class);
        $payments->method('create')->willReturn(new Payment([
            'id' => 'pay_123',
            'status' => PaymentStatus::PENDING,
            'next_action' => new PaymentNextAction([
                'type' => PaymentNextAction::TYPE_COMPLETE,
                'redirect_url' => 'https://secure.monei.com/payments/pay_123',
            ]),
        ]));

        $client = new MoneiApiClient('pk_test_abc123', 'acc_test_456');
        $sdk = (new \ReflectionProperty($client, 'client'))->getValue($client);
        $sdk->payments = $payments;

        $payment = $client->createPayment([
            'amount' => 1999,
            'currency' => 'EUR',
            'orderId' => '000001',
            'completeUrl' => 'https://shop.test/complete',
            'callbackUrl' => 'https://shop.test/notify',
        ]);

        $this->assertSame('PENDING', $payment['status']);
        $this->assertSame('https://secure.monei.com/payments/pay_123', $payment['nextAction']['redirectUrl'] ?? null);
    }
}
