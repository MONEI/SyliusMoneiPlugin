<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Admin;

use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Monei\SyliusPlugin\Tests\TestApplication\src\CommitPaymentStateListener;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Response;

/** Admin complete and refund of a MONEI payment (HTML routes and the admin API). */
final class PaymentTransitionTest extends MoneiTestCase
{
    public function testCompleteOnAnAuthorizedPaymentCapturesIt(): void
    {
        $order = $this->authorizedOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'AUTHORIZED'), self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->transition($order, 'complete');

        self::assertSame(['GET /payments/pay_1', 'POST /payments/pay_1/capture'], $this->requestKeys());
        self::assertSame('completed', $this->paymentState($order));
    }

    public function testARefusedCaptureLeavesThePaymentAuthorizedWithTheErrorText(): void
    {
        $order = $this->authorizedOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'AUTHORIZED'), self::error(400, 'The payment cannot be captured'));

        $this->transition($order, 'complete');

        self::assertSame('authorized', $this->paymentState($order));
        $this->assertOrderPageShows($order, 'MONEI did not capture the payment (The payment cannot be captured).');
    }

    public function testACaptureMoneiIsStillRunningAsksToTryAgain(): void
    {
        $order = $this->authorizedOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'AUTHORIZED'), self::error(409, 'Request already in progress'));

        $this->transition($order, 'complete');

        self::assertSame('authorized', $this->paymentState($order));
        $this->assertOrderPageShows($order, 'MONEI is processing another operation on this payment. Try again in a moment.');
    }

    public function testACaptureAnswerThatIsNotSucceededIsRefused(): void
    {
        $order = $this->authorizedOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'AUTHORIZED'), self::moneiPayment('pay_1', 'AUTHORIZED'));

        $this->transition($order, 'complete');

        self::assertSame('authorized', $this->paymentState($order));
        $this->assertOrderPageShows($order, 'MONEI did not capture the payment (AUTHORIZED).');
    }

    public function testCompleteWhenMoneiAlreadyCapturedMakesNoCaptureCall(): void
    {
        $order = $this->authorizedOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->transition($order, 'complete');

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('completed', $this->paymentState($order));
    }

    public function testCompleteOfAPendingMoneiPaymentIsRefused(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));

        $this->transition($order, 'complete');

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
    }

    public function testCompleteWithoutAMoneiPaymentMakesNoMoneiCall(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));

        $this->transition($order, 'complete');

        self::assertSame([], $this->requestKeys());
        self::assertSame('completed', $this->paymentState($order));
    }

    public function testCompleteAfterASwitchToAnOfflineMethodMakesNoMoneiCall(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $payment->setMethod($this->createOfflinePaymentMethod('BANK_TRANSFER'));
        $this->entityManager()->flush();

        $this->transition($order, 'complete');

        self::assertSame([], $this->requestKeys());
        self::assertSame('completed', $this->paymentState($order));
    }

    public function testCompleteOfAnAuthorizedMoneiPaymentOneCentShortIsRefusedWithoutCapture(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'AUTHORIZED', amount: self::PRODUCT_PRICE - 1));

        $this->transition($order, 'complete');

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
    }

    /** A concurrent request completed the payment while this one waited for the row lock: no double capture. */
    public function testCompleteIsRefusedWhenThePaymentStateChangedWhileItWaited(): void
    {
        $order = $this->authorizedOrder();
        CommitPaymentStateListener::commitOnce('completed');

        $this->transition($order, 'complete');

        self::assertSame([], $this->requestKeys());
        $this->assertOrderPageShows($order, 'The payment changed while this action waited.');
    }

    public function testRefundOfASucceededPaymentRefundsTheFullAmount(): void
    {
        $order = $this->completedOrder();
        MoneiMockHandler::append(
            self::moneiPayment('pay_1', 'SUCCEEDED'),
            self::moneiPayment('pay_1', 'REFUNDED', extra: ['refundedAmount' => 10000]),
        );

        $this->transition($order, 'refund');

        self::assertSame(['GET /payments/pay_1', 'POST /payments/pay_1/refund'], $this->requestKeys());
        self::assertSame(10000, self::lastRequestBody()['amount']);
        self::assertSame('refunded', $this->paymentState($order));
    }

    public function testRefundOfAPartiallyRefundedPaymentRefundsTheRest(): void
    {
        $order = $this->completedOrder();
        MoneiMockHandler::append(
            self::moneiPayment('pay_1', 'PARTIALLY_REFUNDED', extra: ['refundedAmount' => 500]),
            self::moneiPayment('pay_1', 'REFUNDED', extra: ['refundedAmount' => 10000]),
        );

        $this->transition($order, 'refund');

        self::assertSame(9500, self::lastRequestBody()['amount']);
        self::assertSame('refunded', $this->paymentState($order));
    }

    /** MONEI answers a refund equal to one sent less than 3 s before with that refund's answer, and refunds nothing. */
    public function testARefundMoneiDidNotApplyLeavesThePaymentCompleted(): void
    {
        $order = $this->completedOrder();
        MoneiMockHandler::append(
            self::moneiPayment('pay_1', 'PARTIALLY_REFUNDED', extra: ['refundedAmount' => 5000]),
            self::moneiPayment('pay_1', 'PARTIALLY_REFUNDED', extra: ['refundedAmount' => 5000]),
        );

        $this->transition($order, 'refund');

        self::assertSame(['GET /payments/pay_1', 'POST /payments/pay_1/refund'], $this->requestKeys());
        self::assertSame('completed', $this->paymentState($order));
        $this->assertOrderPageShows($order, 'MONEI did not apply this refund');
    }

    /** After an API key change to another account, a refund must not be recorded only in Sylius. */
    public function testRefundIsRefusedWhenTheBoundPaymentCannotBeFetched(): void
    {
        $order = $this->completedOrder();
        MoneiMockHandler::append(self::error(404, 'Resource not found'));

        $this->transition($order, 'refund');

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('completed', $this->paymentState($order));
    }

    public function testRefundWhenMoneiAlreadyRefundedMakesNoRefundCall(): void
    {
        $order = $this->completedOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'REFUNDED'));

        $this->transition($order, 'refund');

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('refunded', $this->paymentState($order));
    }

    public function testARefusedRefundLeavesThePaymentCompletedWithTheErrorText(): void
    {
        $order = $this->completedOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'), self::error(400, 'The payment cannot be refunded'));

        $this->transition($order, 'refund');

        self::assertSame('completed', $this->paymentState($order));
        $this->assertOrderPageShows($order, 'MONEI did not refund the payment (The payment cannot be refunded).');
    }

    public function testAdminApiRefundRefusedByMoneiAnswers409(): void
    {
        $order = $this->completedOrder();
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'), self::error(400, 'The payment cannot be refunded'));

        $this->client->request('PATCH', '/api/v2/admin/payments/'.$this->paymentId($order).'/refund', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminApiToken(),
            'CONTENT_TYPE' => 'application/merge-patch+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], content: '{}');

        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
        self::assertSame(['GET /payments/pay_1', 'POST /payments/pay_1/refund'], $this->requestKeys());
        self::assertSame('completed', $this->paymentState($order));
    }

    private function completedOrder(): OrderInterface
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');
        $this->applyMoneiStatus($order, 'pay_1', 'SUCCEEDED');

        return $order;
    }

    private function transition(OrderInterface $order, string $transition): void
    {
        $this->loginAdmin();
        $paymentId = $this->paymentId($order);
        $this->client->request('PUT', '/admin/orders/'.$order->getId().'/payments/'.$paymentId.'/'.$transition, [
            '_csrf_token' => $this->csrfToken($paymentId),
        ]);
        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertNoOpenTransaction();
    }
}
