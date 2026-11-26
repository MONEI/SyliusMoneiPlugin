<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Admin;

use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Monolog\Level;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\HttpFoundation\Response;

/** Admin order cancel of an order with a MONEI payment (HTML route and the admin API). */
final class OrderCancelTest extends MoneiTestCase
{
    public function testCancelReleasesTheAuthorizedHold(): void
    {
        $order = $this->authorizedOrder();
        MoneiMockHandler::append(
            self::moneiPayment('pay_1', 'AUTHORIZED'),
            self::moneiPayment('pay_1', 'AUTHORIZED'),
            self::moneiPayment('pay_1', 'CANCELED'),
        );

        $this->cancel($order);

        self::assertSame(['GET /payments/pay_1', 'GET /payments/pay_1', 'POST /payments/pay_1/cancel'], $this->requestKeys());
        self::assertSame(['cancelled', 'cancelled'], [$this->orderState($order), $this->paymentState($order)]);
    }

    public function testARefusedHoldReleaseKeepsTheOrderOpenWithTheErrorText(): void
    {
        $order = $this->authorizedOrder();
        MoneiMockHandler::append(
            self::moneiPayment('pay_1', 'AUTHORIZED'),
            self::moneiPayment('pay_1', 'AUTHORIZED'),
            self::error(400, 'The payment cannot be canceled'),
        );

        $this->cancel($order);

        self::assertSame(['new', 'authorized'], [$this->orderState($order), $this->paymentState($order)]);
        $this->assertOrderPageShows($order, 'MONEI did not cancel the payment (The payment cannot be canceled).');
    }

    public function testCancelIsRefusedWhenMoneiSaysPaid(): void
    {
        $order = $this->boundOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->cancel($order);

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame(['new', 'new'], [$this->orderState($order), $this->paymentState($order)]);
        $this->assertOrderPageShows($order, 'MONEI says a payment of this order is SUCCEEDED');
    }

    public function testCancelCancelsThePendingMoneiPayment(): void
    {
        $order = $this->boundOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'), self::moneiPayment('pay_1', 'CANCELED'));

        $this->cancel($order);

        self::assertSame(['GET /payments/pay_1', 'POST /payments/pay_1/cancel'], $this->requestKeys());
        self::assertSame('cancelled', $this->orderState($order));
    }

    /** User decision: an order with a pending payment can always be cancelled. */
    public function testCancelGoesOnWithAWarningWhenThePendingPaymentCannotBeCancelled(): void
    {
        $order = $this->boundOrder();
        MoneiMockHandler::append(
            self::moneiPayment('pay_1', 'PENDING'),
            self::error(400, 'The payment cannot be canceled'),
            self::moneiPayment('pay_1', 'PENDING'),
        );

        $this->cancel($order);

        self::assertSame(['GET /payments/pay_1', 'POST /payments/pay_1/cancel', 'GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('cancelled', $this->orderState($order));
        $this->client->followRedirect();
        self::assertStringContainsString('MONEI could not cancel its pending payment', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAbandonedOrderIsCancelledWithoutMonei(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));

        $this->cancel($order);

        self::assertSame([], $this->requestKeys());
        self::assertSame('cancelled', $this->orderState($order));
    }

    public function testCancelIsRefusedWhenMoneiHoldsAnUnrecordedAuthorization(): void
    {
        $order = $this->boundOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'AUTHORIZED'));

        $this->cancel($order);

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('new', $this->orderState($order));
    }

    /** User decision: a bound payment the plugin cannot reach (API key changed) does not block the cancel. */
    public function testCancelGoesOnWithACriticalLogWhenTheBoundPaymentCannotBeFetched(): void
    {
        $order = $this->boundOrder();
        MoneiMockHandler::append(self::error(404, 'Resource not found'));

        $this->cancel($order);

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('cancelled', $this->orderState($order));
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }

    /** User decision: the hold expires at MONEI, so the listener's fetch must not refuse either. */
    public function testDisconnectDoesNotBlockCancellingAnOrderWhosePaymentMayStillBePayable(): void
    {
        $order = $this->placeOrder($this->createConnectedTestMethod());
        $this->bindPayment($order, 'pay_1', 'MONEI_CONNECTED');
        $this->disconnectMonei();

        $this->cancel($order);

        self::assertSame([], $this->requestKeys());
        self::assertSame('cancelled', $this->orderState($order));
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }

    public function testCancelOfAnUnreachableAuthorizedPaymentGoesOn(): void
    {
        $order = $this->authorizedOrder();
        MoneiMockHandler::append(self::error(404, 'Resource not found'), self::error(404, 'Resource not found'));

        $this->cancel($order);

        self::assertSame(['GET /payments/pay_1', 'GET /payments/pay_1'], $this->requestKeys());
        self::assertSame(['cancelled', 'cancelled'], [$this->orderState($order), $this->paymentState($order)]);
    }

    public function testCancelIsRefusedWhenTheGuardCannotFetchThePayment(): void
    {
        $order = $this->boundOrder();
        MoneiMockHandler::append(self::error(500, 'Internal server error'));

        $this->cancel($order);

        self::assertSame('new', $this->orderState($order));
    }

    /** MONEI sends no callback for EXPIRED: the hold is gone and must not block the cancel. */
    public function testCancelOfAnExpiredHoldMakesNoCancelCall(): void
    {
        $order = $this->authorizedOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'EXPIRED'), self::moneiPayment('pay_1', 'EXPIRED'));

        $this->cancel($order);

        self::assertSame(['GET /payments/pay_1', 'GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('cancelled', $this->orderState($order));
    }

    public function testAdminApiCancelOfAPaidOrderAnswers409(): void
    {
        $order = $this->boundOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->client->request('PATCH', '/api/v2/admin/orders/'.$order->getTokenValue().'/cancel', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminApiToken(),
            'CONTENT_TYPE' => 'application/merge-patch+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], content: '{}');

        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
        self::assertSame('new', $this->orderState($order));
    }

    private function boundOrder(): OrderInterface
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');

        return $order;
    }

    private function cancel(OrderInterface $order): void
    {
        $this->loginAdmin();
        $orderId = $order->getId();
        \assert(\is_int($orderId));
        $this->client->request('PUT', '/admin/orders/'.$orderId.'/cancel', ['_csrf_token' => $this->csrfToken($orderId)]);
        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertNoOpenTransaction();
    }
}
