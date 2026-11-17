<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Shop;

use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Monolog\Level;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Response;

/** The return page (after-pay URL) after a hosted pay. MONEI is mocked. */
final class AfterPayTest extends MoneiTestCase
{
    public function testSucceededRedirectsToTheThankYouPageWithThePaymentCompleted(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $afterPayUrl = $this->payHosted($order);
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->client->request('GET', $afterPayUrl);

        self::assertRedirectsTo('/en_US/order/thank-you', Response::HTTP_FOUND);
        self::assertSame('completed', $this->paymentState($order));
    }

    public function testFailedRedirectsToTheOrderPageWithTheFailedFlash(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $afterPayUrl = $this->payHosted($order);
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'FAILED'));

        $this->client->request('GET', $afterPayUrl);

        self::assertRedirectsTo($this->orderPageUrl($order), Response::HTTP_FOUND);
        self::assertSame('failed', $this->paymentState($order));
        $this->client->followRedirect();
        self::assertStringContainsString('Payment has failed.', (string) $this->client->getResponse()->getContent());
    }

    /** The buyer cancelled on the hosted page: the MONEI payment stays PENDING and payable. */
    public function testPendingRedirectsToTheOrderPageWithThePaymentNew(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $afterPayUrl = $this->payHosted($order);
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));

        $this->client->request('GET', $afterPayUrl);

        self::assertRedirectsTo($this->orderPageUrl($order), Response::HTTP_FOUND);
        self::assertSame('new', $this->paymentState($order));
        self::assertSame([['capture', 'processing'], ['status', 'completed']], $this->paymentRequests($order));
    }

    /** A failed payment could no longer be completed by its SUCCEEDED callback. */
    public function testAMonei500OnTheFetchRedirectsToTheOrderPageWithThePaymentNew(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $afterPayUrl = $this->payHosted($order);
        MoneiMockHandler::append(self::error(500, 'Internal server error'));

        $this->client->request('GET', $afterPayUrl);

        self::assertRedirectsTo($this->orderPageUrl($order), Response::HTTP_FOUND);
        self::assertSame('new', $this->paymentState($order));
        self::assertSame([['capture', 'processing'], ['status', 'failed']], $this->paymentRequests($order));
    }

    /** The bound payment is in another MONEI account (API key changed): no 500 page. */
    public function testAnUnreachableBoundPaymentRedirectsToTheOrderPageWithThePaymentNew(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $afterPayUrl = $this->payHosted($order);
        MoneiMockHandler::append(self::error(404, 'Resource not found'));

        $this->client->request('GET', $afterPayUrl);

        self::assertRedirectsTo($this->orderPageUrl($order), Response::HTTP_FOUND);
        self::assertSame('new', $this->paymentState($order));
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }

    public function testAfterAMonei500OnCreateTheBuyerEndsOnTheOrderPageWithTheFailedFlash(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        MoneiMockHandler::append(self::error(500, 'Internal server error'));
        $this->pay($order);

        // Nothing is bound, so the return page calls MONEI no more: an extra request would fail the test in tearDown.
        $this->client->followRedirect();
        self::assertRedirectsTo($this->orderPageUrl($order), Response::HTTP_FOUND);
        $this->client->followRedirect();

        self::assertSame('failed', $this->paymentState($order));
        self::assertStringContainsString('Payment has failed.', (string) $this->client->getResponse()->getContent());
    }

    /** The return page and a callback at the same time must not both apply the status. */
    public function testTheReturnPageWaitsForThePaymentRowLock(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $afterPayUrl = $this->payHosted($order);
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $this->holdPaymentRowLock($payment);

        $this->client->request('GET', $afterPayUrl);

        self::assertSame(['POST /payments'], $this->requestKeys());
    }

    /** Pays through MONEI's hosted page (MONEI payment `pay_1`); returns the after-pay path. */
    private function payHosted(OrderInterface $order): string
    {
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));
        $payUrl = $this->startPay($order);
        $this->client->request('GET', $payUrl);
        self::assertRedirectsTo('https://secure.monei.com/payments/pay_1', Response::HTTP_SEE_OTHER);

        return '/en_US/order/after-pay/'.self::hash($payUrl);
    }

    private function orderPageUrl(OrderInterface $order): string
    {
        return '/en_US/order/'.$order->getTokenValue();
    }
}
