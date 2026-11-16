<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Shop;

use Monei\Internal\GuzzleHttp\Psr7\Response as MoneiResponse;
use Monei\Internal\Psr\Http\Message\RequestInterface;
use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Monolog\Level;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/** The shop pay flow up to the pay response. MONEI is mocked; the after-pay page is not followed. */
final class HostedPayTest extends MoneiTestCase
{
    private const OTHER_API_KEY = 'pk_test_fedcba9876543210fedcba9876543210';

    public function testPayCreatesThePaymentWithTheCallbackAndAfterPayUrlsAndRedirectsToMonei(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $paymentId = $this->paymentId($order);
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));

        $payUrl = $this->startPay($order);
        $this->client->request('GET', $payUrl);

        $create = $this->onlyRequest('POST /payments');
        self::assertSame('http://localhost/payment-methods/MONEI_CARD?payment='.$paymentId, $create['callbackUrl']);
        self::assertSame('http://localhost/en_US/order/after-pay/'.self::hash($payUrl), $create['completeUrl']);
        self::assertRedirectsTo('https://secure.monei.com/payments/pay_1', Response::HTTP_SEE_OTHER);
    }

    /** A `new` request does not keep the payment amount against cart changes (Sylius 2.2.9+). */
    public function testTheCaptureRequestIsProcessingAfterThePay(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));

        $this->pay($order);

        self::assertSame([['capture', 'processing']], $this->paymentRequests($order));
    }

    public function testAMethodWithUseAuthorizePaysThroughAnAuthorizeRequestAndSendsAuth(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD', ['use_authorize' => true]));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));

        $this->pay($order);

        self::assertSame('AUTH', $this->onlyRequest('POST /payments')['transactionType']);
        self::assertSame([['authorize', 'processing']], $this->paymentRequests($order));
        self::assertRedirectsTo('https://secure.monei.com/payments/pay_1', Response::HTTP_SEE_OTHER);
    }

    public function testPayingAgainReusesThePendingMoneiPayment(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));
        $this->pay($order);

        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));
        $this->pay($order);

        self::assertSame(['POST /payments', 'GET /payments/pay_1'], $this->requestKeys());
        self::assertRedirectsTo('https://secure.monei.com/payments/pay_1', Response::HTTP_SEE_OTHER);
    }

    public function testAMonei500OnTheFetchFailsTheRequestOnlyAndTheNextPayReusesThePayment(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::error(500, 'Internal server error'));

        $payUrl = $this->startPay($order);
        $this->client->request('GET', $payUrl);

        self::assertRedirectsTo('/en_US/order/after-pay/'.self::hash($payUrl), Response::HTTP_FOUND);
        self::assertSame([['capture', 'failed']], $this->paymentRequests($order));
        self::assertSame('new', $this->paymentState($order));

        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));
        $this->pay($order);

        self::assertSame(['GET /payments/pay_1', 'GET /payments/pay_1'], $this->requestKeys());
        self::assertRedirectsTo('https://secure.monei.com/payments/pay_1', Response::HTTP_SEE_OTHER);
    }

    public function testAMonei404OnTheFetchCreatesANewPaymentAndAlerts(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_other_account', 'MONEI_CARD');
        MoneiMockHandler::append(self::error(404, 'Resource not found'), self::moneiPayment('pay_2', 'PENDING'));

        $this->pay($order);

        self::assertSame(['GET /payments/pay_other_account', 'POST /payments'], $this->requestKeys());
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
        self::assertRedirectsTo('https://secure.monei.com/payments/pay_2', Response::HTTP_SEE_OTHER);
    }

    public function testABoundPendingPaymentWithAnotherAmountIsCancelledAndReplaced(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(
            self::moneiPayment('pay_1', 'PENDING', amount: 5000),
            self::moneiPayment('pay_1', 'CANCELED', amount: 5000),
            self::moneiPayment('pay_2', 'PENDING'),
        );

        $this->pay($order);

        self::assertSame(['GET /payments/pay_1', 'POST /payments/pay_1/cancel', 'POST /payments'], $this->requestKeys());
        self::assertSame(10000, $this->body(MoneiMockHandler::history()[2])['amount']);
    }

    public function testAfterAMethodChangeTheOldPaymentIsCancelledWithTheOldKeyAndTheNewOneCreatedWithTheNewKey(): void
    {
        $this->createMoneiPaymentMethod('MONEI_OLD');
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_NEW', ['api_key' => self::OTHER_API_KEY]));
        $this->bind($order, 'pay_old', 'MONEI_OLD');
        MoneiMockHandler::append(
            self::moneiPayment('pay_old', 'PENDING'),
            self::moneiPayment('pay_old', 'CANCELED'),
            self::moneiPayment('pay_new', 'PENDING'),
        );

        $this->pay($order);

        self::assertSame(['GET /payments/pay_old', 'POST /payments/pay_old/cancel', 'POST /payments'], $this->requestKeys());
        self::assertSame(
            [self::API_KEY, self::API_KEY, self::OTHER_API_KEY],
            array_map(static fn (RequestInterface $request): string => $request->getHeaderLine('Authorization'), MoneiMockHandler::history()),
        );
        self::assertRedirectsTo('https://secure.monei.com/payments/pay_new', Response::HTTP_SEE_OTHER);
    }

    /** MONEI sends no callback for EXPIRED. */
    public function testAProcessingRequestWhoseBoundPaymentExpiredCreatesOneNewPayment(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));
        $this->pay($order);

        MoneiMockHandler::append(self::moneiPayment('pay_1', 'EXPIRED'), self::moneiPayment('pay_2', 'PENDING'));
        $this->pay($order);

        self::assertSame(['POST /payments', 'GET /payments/pay_1', 'POST /payments'], $this->requestKeys());
        self::assertRedirectsTo('https://secure.monei.com/payments/pay_2', Response::HTTP_SEE_OTHER);
    }

    public function testAfterACardToCardChangeAnUncancellablePendingPaymentBlocksANewOne(): void
    {
        $this->createMoneiPaymentMethod('MONEI_CARD_A');
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD_B'));
        $this->bind($order, 'pay_old', 'MONEI_CARD_A');
        $orderId = (string) $order->getNumber();
        MoneiMockHandler::append(
            self::moneiPayment('pay_old', 'PENDING', extra: ['orderId' => $orderId]),
            self::error(400, 'The payment cannot be canceled'),
            self::moneiPayment('pay_old', 'PENDING', extra: ['orderId' => $orderId]),
        );

        $this->pay($order);

        self::assertSame(['GET /payments/pay_old', 'POST /payments/pay_old/cancel', 'GET /payments/pay_old'], $this->requestKeys());
        self::assertContains(Level::Warning, array_column($this->moneiLogs(), 'level'));
        self::assertSame('new', $this->paymentState($order));
        self::assertSame(['id' => 'pay_old', 'payment_method' => 'MONEI_CARD_A'], $this->paymentDetails($order)['monei']);
    }

    public function testAfterAMultibancoToCardChangeAnUncancellablePendingPaymentDoesNotBlockTheCardPayment(): void
    {
        $this->createMoneiPaymentMethod('MONEI_MB', ['payment_method' => 'multibanco']);
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_old', 'MONEI_MB');
        $orderNumber = (string) $order->getNumber();
        $multibancoOrderId = $orderNumber.'-'.$this->paymentId($order);
        MoneiMockHandler::append(
            self::moneiPayment('pay_old', 'PENDING', extra: ['orderId' => $multibancoOrderId]),
            self::error(400, 'The payment cannot be canceled'),
            self::moneiPayment('pay_old', 'PENDING', extra: ['orderId' => $multibancoOrderId]),
            self::moneiPayment('pay_card', 'PENDING'),
        );

        $this->pay($order);

        self::assertSame($orderNumber, $this->body(MoneiMockHandler::history()[3])['orderId']);
        self::assertContains(Level::Warning, array_column($this->moneiLogs(), 'level'));
        self::assertRedirectsTo('https://secure.monei.com/payments/pay_card', Response::HTTP_SEE_OTHER);
    }

    /** Failing the payment would make Sylius create payment after payment that MONEI refuses. */
    public function testACreateRefusedByTheDuplicateCheckKeepsThePaymentNewAndLogsAnError(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        MoneiMockHandler::append(self::error(400, \sprintf('The order "%s" has already been paid', (string) $order->getNumber())));

        $this->pay($order);

        self::assertSame('new', $this->paymentState($order));
        self::assertContains(Level::Error, array_column($this->moneiLogs(), 'level'));
    }

    public function testACreateRefusedWithAValidationErrorFailsThePayment(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        MoneiMockHandler::append(self::error(400, 'Invalid customer email'));

        $this->pay($order);

        self::assertSame('failed', $this->paymentState($order));
    }

    public function testACreateAnsweredWithAFailedPaymentFailsThePaymentAndLogsTheReason(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD', ['payment_method' => 'multibanco']));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'FAILED', extra: ['statusMessage' => 'Payment method not enabled']));

        $this->pay($order);

        self::assertSame('failed', $this->paymentState($order));
        self::assertStringContainsString('Payment method not enabled', implode(' ', array_column($this->moneiLogs(), 'text')));
    }

    /** MONEI expires the payment one hour before `sylius:cancel-unpaid-orders` (5 days after checkout) cancels the order. */
    public function testTheCreateSetsExpireAtOneHourBeforeTheOrderExpires(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $checkoutCompletedAt = $this->secondConnection()->fetchOne('SELECT checkout_completed_at FROM sylius_order WHERE id = ?', [$order->getId()]);
        \assert(\is_string($checkoutCompletedAt));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));

        $this->pay($order);

        $expected = (new \DateTimeImmutable($checkoutCompletedAt, new \DateTimeZone('UTC')))->getTimestamp() + 5 * 86400 - 3600;
        self::assertSame($expected, $this->onlyRequest('POST /payments')['expireAt']);
    }

    /** MONEI refuses an expireAt in the past. */
    public function testAnOrderCloseToItsExpiryGetsExpireAtOneHourFromNow(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->secondConnection()->executeStatement('UPDATE sylius_order SET checkout_completed_at = ? WHERE id = ?', [
            (new \DateTimeImmutable('-6 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            $order->getId(),
        ]);
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));

        $before = time();
        $this->pay($order);
        $after = time();

        $expireAt = $this->onlyRequest('POST /payments')['expireAt'];
        self::assertGreaterThanOrEqual($before + 3600, $expireAt);
        self::assertLessThanOrEqual($after + 3600, $expireAt);
    }

    public function testAPendingPaymentWithTheProcessorIsNotReopenedAndTheBuyerSeesItIsInProgress(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING', nextAction: [
            'type' => 'COMPLETE',
            'redirectUrl' => 'http://localhost/en_US/order/after-pay/x?id=pay_1&status=PENDING',
        ]));

        $payUrl = $this->startPay($order);
        $this->client->request('GET', $payUrl);
        self::assertRedirectsTo('/en_US/order/after-pay/'.self::hash($payUrl), Response::HTTP_FOUND);

        $this->client->request('GET', '/en_US/order/'.$order->getTokenValue());
        self::assertStringContainsString(
            'A payment for this order is still in progress. Complete it or wait until it expires.',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** An abandoned 3DS resumes at its challenge. */
    public function testAPendingPaymentWithAChallengeRedirectsToTheChallenge(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING', nextAction: [
            'type' => 'CHALLENGE',
            'redirectUrl' => 'https://secure.monei.com/payments/pay_1/challenge',
        ]));

        $this->pay($order);

        self::assertRedirectsTo('https://secure.monei.com/payments/pay_1/challenge', Response::HTTP_SEE_OTHER);
    }

    /** Sylius cancels such requests on a normal method change, so the request is built directly. */
    public function testACaptureRequestForAnotherMethodThanThePaymentsSendsNothing(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $otherMethod = $this->createMoneiPaymentMethod('MONEI_OTHER');
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);

        $factory = self::getContainer()->get('sylius.factory.payment_request');
        \assert($factory instanceof PaymentRequestFactoryInterface);
        $paymentRequest = $factory->create($payment, $otherMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        $this->entityManager()->persist($paymentRequest);
        $this->entityManager()->flush();

        $this->client->request('GET', '/en_US/payment-request/pay/'.$paymentRequest->getId());

        self::assertSame([], MoneiMockHandler::history());
    }

    /** Browser Back after a decline. */
    public function testThePayUrlOfAProcessingRequestWhosePaymentFailedSendsNothing(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));
        $payUrl = $this->startPay($order);
        $this->client->request('GET', $payUrl);
        $this->secondConnection()->executeStatement("UPDATE sylius_payment SET state = 'failed' WHERE id = ?", [$this->paymentId($order)]);

        $this->client->request('GET', $payUrl);

        self::assertSame(['POST /payments'], $this->requestKeys());
    }

    public function testABoundSucceededPaymentCompletesThePaymentAndCreatesNothing(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->pay($order);

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('completed', $this->paymentState($order));
        self::assertSame([['capture', 'completed']], $this->paymentRequests($order));
    }

    /** Two pay requests at once (a double click) must not both create a MONEI payment. */
    public function testThePayWaitsForThePaymentRowLock(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $payUrl = $this->startPay($order);
        $this->holdPaymentRowLock($payment);

        $this->client->request('GET', $payUrl);

        self::assertSame([], MoneiMockHandler::history());
    }

    public function testAnOrderInACurrencyWithoutTwoDecimalsFailsThePaymentWithoutCallingMonei(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->secondConnection()->executeStatement("UPDATE sylius_order SET currency_code = 'JPY' WHERE id = ?", [$order->getId()]);
        $this->secondConnection()->executeStatement("UPDATE sylius_payment SET currency_code = 'JPY' WHERE id = ?", [$this->paymentId($order)]);

        $this->pay($order);

        self::assertSame([], MoneiMockHandler::history());
        self::assertSame('failed', $this->paymentState($order));
        self::assertStringContainsString('"JPY"', implode(' ', array_column($this->moneiLogs(), 'text')));
    }

    public function testAMonei500OnCreateFailsThePaymentAndKeepsTheApiKeyOutOfTheLogs(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        MoneiMockHandler::append(self::error(500, 'Internal server error'));

        $this->pay($order);

        self::assertSame('failed', $this->paymentState($order));
        $logs = $this->moneiLogs();
        self::assertContains(Level::Error, array_column($logs, 'level'));
        self::assertStringNotContainsString(self::API_KEY, implode(' ', array_column($logs, 'text')));
    }

    /** Opens the order pay page; returns the `/pay/{hash}` path Sylius redirects to. */
    private function startPay(OrderInterface $order): string
    {
        $this->client->request('GET', '/en_US/order/'.$order->getTokenValue().'/pay');
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/en_US/payment-request/pay/[0-9a-f-]{36}$#', $location);

        return $location;
    }

    private function pay(OrderInterface $order): void
    {
        $this->client->request('GET', $this->startPay($order));
    }

    private static function hash(string $payUrl): string
    {
        return substr($payUrl, -36);
    }

    private static function assertRedirectsTo(string $url, int $status): void
    {
        $response = self::getClient()?->getResponse();
        \assert($response instanceof Response);
        self::assertSame([$status, $url], [$response->getStatusCode(), $response->headers->get('Location')]);
    }

    private function bind(OrderInterface $order, string $moneiPaymentId, string $paymentMethodCode): void
    {
        $this->secondConnection()->executeStatement('UPDATE sylius_payment SET details = ? WHERE id = ?', [
            json_encode(['monei' => ['id' => $moneiPaymentId, 'payment_method' => $paymentMethodCode]], \JSON_THROW_ON_ERROR),
            $this->paymentId($order),
        ]);
    }

    private function paymentId(OrderInterface $order): int
    {
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $id = $payment->getId();
        \assert(\is_int($id));

        return $id;
    }

    /** Read by id: a failed payment makes Sylius add a new payment to the order. */
    private function paymentState(OrderInterface $order): string
    {
        $state = $this->secondConnection()->fetchOne('SELECT state FROM sylius_payment WHERE id = ?', [$this->paymentId($order)]);
        \assert(\is_string($state));

        return $state;
    }

    /** @return array<string, mixed> */
    private function paymentDetails(OrderInterface $order): array
    {
        $details = $this->secondConnection()->fetchOne('SELECT details FROM sylius_payment WHERE id = ?', [$this->paymentId($order)]);
        \assert(\is_string($details));

        return json_decode($details, true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return list<array{string, string}> action and state of each payment request of the order's payment */
    private function paymentRequests(OrderInterface $order): array
    {
        /** @var list<array{action: string, state: string}> $rows */
        $rows = $this->secondConnection()->fetchAllAssociative('SELECT action, state FROM sylius_payment_request WHERE payment_id = ?', [$this->paymentId($order)]);

        return array_map(static fn (array $row): array => [$row['action'], $row['state']], $rows);
    }

    /** @return list<string> */
    private function requestKeys(): array
    {
        return array_map(MoneiMockHandler::key(...), MoneiMockHandler::history());
    }

    /** @return array<string, mixed> the body of the only MONEI request, which must be `$key` */
    private function onlyRequest(string $key): array
    {
        self::assertSame([$key], $this->requestKeys());

        return $this->body(MoneiMockHandler::history()[0]);
    }

    /** @return array<string, mixed> */
    private function body(RequestInterface $request): array
    {
        return json_decode((string) $request->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string>|null $nextAction defaults to CONFIRM on the MONEI payment page for PENDING
     * @param array<string, mixed>       $extra
     */
    private static function moneiPayment(string $id, string $status, int $amount = self::PRODUCT_PRICE, ?array $nextAction = null, array $extra = []): MoneiResponse
    {
        $body = ['id' => $id, 'status' => $status, 'amount' => $amount, 'currency' => 'EUR'] + $extra;
        if (null === $nextAction && 'PENDING' === $status) {
            $nextAction = ['type' => 'CONFIRM', 'redirectUrl' => 'https://secure.monei.com/payments/'.$id];
        }
        if (null !== $nextAction) {
            $body['nextAction'] = $nextAction;
        }

        return new MoneiResponse(200, ['Content-Type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private static function error(int $statusCode, string $message): MoneiResponse
    {
        return new MoneiResponse($statusCode, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'ERROR',
            'statusCode' => $statusCode,
            'message' => $message,
            'requestId' => 'req_test',
        ], \JSON_THROW_ON_ERROR));
    }
}
