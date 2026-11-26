<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Callback;

use Doctrine\ORM\Event\PostPersistEventArgs;
use Monei\Internal\Psr\Http\Message\RequestInterface;
use Monei\Internal\Psr\Http\Message\ResponseInterface;
use Monei\SyliusPlugin\Connect\ConnectionStore;
use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;

/** MONEI callbacks on `/payment-methods/{code}?payment=<id>`. MONEI is mocked. */
final class CallbackTest extends MoneiTestCase
{
    use ClockSensitiveTrait;

    private const CONNECTED_ID = 'aabbccddeeff00112233445566778899';
    private const CONNECTED_BODY = '{"id":"'.self::CONNECTED_ID.'","status":"SUCCEEDED"}';
    private const CONNECTED_ORDER_ID = 'original-order';

    private const OTHER_API_KEY = 'pk_test_fedcba9876543210fedcba9876543210';

    private const BODY = '{"id":"pay_1","status":"SUCCEEDED","amount":10000,"currency":"EUR"}';

    /** HMAC-SHA256 of "1763300000.".BODY with MoneiTestCase::API_KEY, computed with Python's hmac. */
    private const SIGNATURE = 't=1763300000,v1=092ddaa9ac309f99014c5c05b119651c9b943e8d1fcfb5e401e5385b64fe6c6f';

    public function testAnUnsignedCallbackIsRefusedWithoutAMoneiCall(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');

        $this->postCallback('MONEI_CARD', $this->paymentId($order), null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
        self::assertSame([], $this->paymentRequests($order));
    }

    public function testASignedSucceededCallbackCompletesThePayment(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        self::assertSame('completed', $this->paymentState($order));
        self::assertSame([['notify', 'completed']], $this->paymentRequests($order));
    }

    /** MONEI must not retry a callback for a payment the store no longer has. */
    public function testACallbackForAnUnknownSyliusPaymentAnswers204AndLogsAWarning(): void
    {
        $this->createMoneiPaymentMethod('MONEI_CARD');

        $this->postCallback('MONEI_CARD', 999999);

        self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        self::assertContains(Level::Warning, array_column($this->moneiLogs(), 'level'));
    }

    /** The old MONEI payment of method A is paid after the buyer switched to method B. */
    public function testAPaidOldPaymentAfterAMethodSwitchIsFetchedWithTheSigningKeyAndReported(): void
    {
        $methodA = $this->createMoneiPaymentMethod('MONEI_CARD');
        $methodB = $this->createMoneiPaymentMethod('MONEI_CARD_B', ['api_key' => self::OTHER_API_KEY]);
        $order = $this->placeOrder($methodA);
        $this->switchMethod($order, $methodB);
        $this->bindPayment($order, 'pay_2', 'MONEI_CARD_B');
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            [self::API_KEY],
            array_map(static fn (RequestInterface $request): string => $request->getHeaderLine('Authorization'), MoneiMockHandler::history()),
        );
        self::assertSame('new', $this->paymentState($order));
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }

    public function testAPaidCallbackForAPaymentWithNoBoundMoneiPaymentIsReported(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        self::assertSame('new', $this->paymentState($order));
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }

    /** MONEI holds money for a payment Sylius now records under bank transfer. */
    public function testAPaidBoundPaymentAfterASwitchToAnOfflineMethodIsReported(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->switchMethod($order, $this->createOfflinePaymentMethod('BANK_TRANSFER'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        self::assertSame('new', $this->paymentState($order));
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }

    public function testAPaidBoundPaymentOfMoneiMethodACompletesAPaymentNowOnMoneiMethodB(): void
    {
        $methodA = $this->createMoneiPaymentMethod('MONEI_CARD');
        $methodB = $this->createMoneiPaymentMethod('MONEI_BIZUM', ['payment_method' => 'bizum', 'api_key' => self::OTHER_API_KEY]);
        $order = $this->placeOrder($methodA);
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');
        $this->switchMethod($order, $methodB);
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertSame('completed', $this->paymentState($order));
    }

    public function testASignedCallbackWithoutThePaymentParameterIsRefused(): void
    {
        $this->createMoneiPaymentMethod('MONEI_CARD');

        $this->client->request('POST', '/payment-methods/MONEI_CARD', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MONEI_SIGNATURE' => self::SIGNATURE,
        ], content: self::BODY);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    /** The signed id, not the bound one, is fetched: the body is never trusted, and an orphan cannot complete the order. */
    public function testASignedCallbackForAnUnboundMoneiPaymentFetchesTheSignedIdAndChangesNothing(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_2', 'MONEI_CARD');
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }

    public function testAMonei500OnTheFetchAnswers5xxSoMoneiRetries(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::error(500, 'Internal server error'));

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertGreaterThanOrEqual(500, $this->client->getResponse()->getStatusCode());
        self::assertSame('new', $this->paymentState($order));
    }

    /** The notify request stays open after the 5xx; its replay route merges any body into the payload. */
    public function testAnUnsignedReplayOfTheOpenNotifyRequestChangesNothing(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::error(500, 'Internal server error'));
        $this->postCallback('MONEI_CARD', $this->paymentId($order));
        $hash = $this->secondConnection()->fetchOne("SELECT BIN_TO_UUID(hash) FROM sylius_payment_request WHERE action = 'notify'");
        \assert(\is_string($hash));

        $this->client->request('POST', '/payment-requests/'.$hash, server: ['CONTENT_TYPE' => 'application/json'], content: self::BODY);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
    }

    /** A handler that fetches before it locks lets the return page and the callback apply at once. */
    public function testACallbackWaitsForThePaymentRowLock(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD');
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $this->holdPaymentRowLock($payment);

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertGreaterThanOrEqual(500, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
    }

    public function testTheSixthUnsignedCallbackAddsNoNotifyRowOrFetchAndTheQuotaExpires(): void
    {
        $order = $this->connectedOrder();
        $clock = Clock::get();
        for ($i = 0; $i < 5; ++$i) {
            MoneiMockHandler::append(self::connectedPayment());
            $this->postConnectedCallback($order);
            self::assertResponseStatusCodeSame(204);
        }

        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(503);
        self::assertCount(5, $this->paymentRequests($order));
        self::assertCount(5, $this->requestKeys());
        self::assertSame(5, $this->attemptCount());

        $clock->sleep(61);
        MoneiMockHandler::append(self::connectedPayment());
        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(204);
        self::assertCount(6, $this->requestKeys());
        self::assertSame(1, $this->attemptCount());
    }

    public function testAttemptsForOtherPaymentsExhaustTheInstallQuota(): void
    {
        $order = $this->connectedOrder();
        $this->seedAttempts($this->paymentId($order) + 1, 60);

        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(503);
        self::assertSame([], $this->paymentRequests($order));
        self::assertSame([], $this->requestKeys());
        self::assertSame(60, $this->attemptCount());
    }

    public function testV2SettlesEvenWhenTheUnsignedQuotaIsSpent(): void
    {
        $order = $this->connectedOrder();
        $this->seedAttempts($this->paymentId($order), 5);
        MoneiMockHandler::append(self::connectedPayment('SUCCEEDED'));

        $this->postConnectedCallback($order, self::v2Signature());

        self::assertResponseStatusCodeSame(204);
        self::assertSame('completed', $this->paymentState($order));
        self::assertSame(5, $this->attemptCount());
    }

    #[DataProvider('authoritativeStatuses')]
    public function testHeaderlessCallbackUsesTheFetchedStatus(string $status, string $expectedState): void
    {
        $order = $this->connectedOrder();
        MoneiMockHandler::append(self::connectedPayment($status));

        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(204);
        self::assertSame($expectedState, $this->paymentState($order));
    }

    /** @return iterable<string, array{string, string}> */
    public static function authoritativeStatuses(): iterable
    {
        yield 'settle from MONEI' => ['SUCCEEDED', 'completed'];
        yield 'ignore the claimed success' => ['PENDING', 'new'];
    }

    public function testUnsignedCallbackForAnEarlierAttemptReportsTheFundsWithoutSettling(): void
    {
        $order = $this->connectedOrder();
        $this->bindPayment($order, str_repeat('b', 32), 'MONEI_CONNECTED', orderId: self::CONNECTED_ORDER_ID);
        MoneiMockHandler::append(self::connectedPayment('SUCCEEDED'));

        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(['GET /payments/'.self::CONNECTED_ID], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }

    public function testUnsignedCallbackWithNoStoredOrderIdCannotTriggerRefundAlerts(): void
    {
        $order = $this->connectedOrder(bindOrderId: false);

        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->requestKeys());
        self::assertSame(0, $this->attemptCount());
        self::assertSame('new', $this->paymentState($order));
        self::assertSame([Level::Warning], array_column($this->moneiLogs(), 'level'));
    }

    public function testDisconnectedMethodAsksMoneiToRetryWithoutCreatingANotifyRequest(): void
    {
        $order = $this->connectedOrder();
        $this->disconnectMonei();

        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(503);
        self::assertSame([], $this->paymentRequests($order));
        self::assertSame([], $this->requestKeys());
        self::assertSame([Level::Error], array_column($this->moneiLogs(), 'level'));
        self::assertStringContainsString(self::CONNECTED_ID, $this->moneiLogs()[0]['text']);
    }

    public function testUnsignedCallbackForADifferentOrderCannotTriggerRefundAlerts(): void
    {
        $order = $this->connectedOrder();
        $this->bindPayment($order, str_repeat('b', 32), 'MONEI_CONNECTED', orderId: self::CONNECTED_ORDER_ID);
        MoneiMockHandler::append(self::moneiPayment(self::CONNECTED_ID, 'SUCCEEDED', extra: ['orderId' => 'other-order']));

        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('new', $this->paymentState($order));
        self::assertSame([Level::Warning], array_column($this->moneiLogs(), 'level'));
    }

    public function testAnUnsignedFetchCannotStartWhileTheQuotaRowIsLocked(): void
    {
        $order = $this->connectedOrder();
        $this->holdRowLock('monei_connection', 1);

        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(503);
        self::assertSame([], $this->requestKeys());
        self::assertSame(0, $this->attemptCount());
    }

    public function testMultibancoCallbackMatchesTheOriginalOrderIdAfterAPrefixChange(): void
    {
        $method = $this->createConnectedTestMethod(['payment_method' => 'multibanco']);
        $order = $this->placeOrder($method);
        $originalOrderId = $order->getNumber().'-'.$this->paymentId($order);
        MoneiMockHandler::append(self::moneiPayment(self::CONNECTED_ID, 'PENDING'));

        $this->pay($order);
        self::assertSame($originalOrderId, self::lastRequestBody()['orderId']);

        $method = $this->entityManager()->find($method::class, $method->getId());
        \assert($method instanceof PaymentMethodInterface);
        $config = $method->getGatewayConfig();
        \assert(null !== $config);
        $config->setConfig(['order_id_prefix' => 'WEB-'] + $config->getConfig());
        $this->entityManager()->flush();

        MoneiMockHandler::append(self::moneiPayment(self::CONNECTED_ID, 'SUCCEEDED', extra: ['orderId' => $originalOrderId]));
        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('completed', $this->paymentState($order));
    }

    public function testAnUnsignedReplayOfTheOpenConnectedNotifyRequestStillNeedsQuota(): void
    {
        $order = $this->connectedOrder();
        MoneiMockHandler::append(self::error(500, 'Internal server error'));
        $this->postConnectedCallback($order, self::v2Signature());
        self::assertGreaterThanOrEqual(500, $this->client->getResponse()->getStatusCode());

        $hash = $this->secondConnection()->fetchOne("SELECT BIN_TO_UUID(hash) FROM sylius_payment_request WHERE action = 'notify'");
        \assert(\is_string($hash));
        $this->seedAttempts($this->paymentId($order), 5);

        $this->client->request('POST', '/payment-requests/'.$hash, server: ['CONTENT_TYPE' => 'application/json'], content: self::CONNECTED_BODY);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['GET /payments/'.self::CONNECTED_ID], $this->requestKeys());
        self::assertSame(5, $this->attemptCount());
    }

    public function testASecretRotatedAfterTheProviderAcceptsV2MakesTheHandlerChargeQuota(): void
    {
        $order = $this->connectedOrder();
        $this->client->disableReboot();
        $store = self::getContainer()->get('monei.test.connection_store');
        // Sylius persists the notify request after the provider and before dispatch.
        $listener = new class($store) {
            public function __construct(private readonly ConnectionStore $store)
            {
            }

            public function postPersist(PostPersistEventArgs $event): void
            {
                $request = $event->getObject();
                if ($request instanceof PaymentRequestInterface && PaymentRequestInterface::ACTION_NOTIFY === $request->getAction()) {
                    $this->store->writeMode(false, MoneiTestCase::ACCOUNT_ID, 'mk_test_rotated_fixture', 'mksec_rotated_fixture');
                }
            }
        };
        $events = $this->entityManager()->getEventManager();
        $events->addEventListener(['postPersist'], $listener);
        MoneiMockHandler::append(self::connectedPayment());

        try {
            $this->postConnectedCallback($order, self::v2Signature());
        } finally {
            $events->removeEventListener(['postPersist'], $listener);
        }

        self::assertResponseStatusCodeSame(204);
        self::assertSame(1, $this->attemptCount());
    }

    #[DataProvider('fetchFailures')]
    public function testUnsignedFetchAcknowledgesPermanentErrorsButRetriesAnActualOutage(int $upstreamStatus, int $callbackStatus): void
    {
        $order = $this->connectedOrder();
        MoneiMockHandler::append(200 === $upstreamStatus
            ? self::connectedPayment('UNKNOWN_STATUS')
            : self::error($upstreamStatus, 'Fetch error'));

        $this->postConnectedCallback($order);

        if (500 === $callbackStatus) {
            self::assertGreaterThanOrEqual(500, $this->client->getResponse()->getStatusCode());
        } else {
            self::assertResponseStatusCodeSame($callbackStatus);
            self::assertContains(Level::Warning, array_column($this->moneiLogs(), 'level'));
        }
        self::assertSame('new', $this->paymentState($order));
    }

    /** @return iterable<string, array{int, int}> */
    public static function fetchFailures(): iterable
    {
        yield 'payment from another account or mode' => [404, 200];
        yield 'SDK cannot read the status' => [200, 200];
        yield 'actual HTTP 502' => [502, 500];
    }

    public function testFailedFetchesKeepTheirQuotaChargesWhenMessengerRollsBack(): void
    {
        $order = $this->connectedOrder();
        for ($i = 0; $i < 5; ++$i) {
            MoneiMockHandler::append(self::error(500, 'Internal server error'));
            $this->postConnectedCallback($order);
            self::assertGreaterThanOrEqual(500, $this->client->getResponse()->getStatusCode());
        }

        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(503);
        self::assertCount(5, $this->requestKeys());
        self::assertCount(5, $this->paymentRequests($order));
        self::assertSame(5, $this->attemptCount());
    }

    public function testTheQuotaRowCanBeLockedByAnotherConnectionDuringTheFetch(): void
    {
        $order = $this->connectedOrder();
        $connection = $this->secondConnection();
        $locked = false;
        MoneiMockHandler::append(static function () use ($connection, &$locked): ResponseInterface {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            $locked = $connection->transactional(static fn (): bool => false !== $connection->fetchOne('SELECT id FROM monei_connection WHERE id = 1 FOR UPDATE'));

            return self::connectedPayment();
        });

        $this->postConnectedCallback($order);

        self::assertResponseStatusCodeSame(204);
        self::assertTrue($locked);
    }

    private function connectedOrder(bool $bindOrderId = true): OrderInterface
    {
        self::mockTime();
        $order = $this->placeOrder($this->createConnectedTestMethod());
        $this->bindPayment($order, self::CONNECTED_ID, 'MONEI_CONNECTED', orderId: $bindOrderId ? self::CONNECTED_ORDER_ID : null);

        return $order;
    }

    private static function connectedPayment(string $status = 'PENDING'): ResponseInterface
    {
        return self::moneiPayment(self::CONNECTED_ID, $status, extra: ['orderId' => self::CONNECTED_ORDER_ID]);
    }

    private static function v2Signature(): string
    {
        return 't=1763300000,v2='.hash_hmac('sha256', '1763300000.'.self::CONNECTED_BODY, 'mksec_test_fixture');
    }

    private function postConnectedCallback(OrderInterface $order, ?string $signature = null): void
    {
        $this->postCallback('MONEI_CONNECTED', $this->paymentId($order), $signature, self::CONNECTED_BODY);
    }

    private function seedAttempts(int $paymentId, int $count): void
    {
        $createdAt = Clock::get()->now()->format('Y-m-d H:i:s');
        for ($i = 0; $i < $count; ++$i) {
            $this->secondConnection()->insert('monei_callback_attempt', ['payment_id' => $paymentId, 'created_at' => $createdAt]);
        }
    }

    private function attemptCount(): int
    {
        return (int) $this->secondConnection()->fetchOne('SELECT COUNT(*) FROM monei_callback_attempt');
    }

    private function postCallback(string $code, int $paymentId, ?string $signature = self::SIGNATURE, string $body = self::BODY): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $signature) {
            $server['HTTP_MONEI_SIGNATURE'] = $signature;
        }

        $this->client->request('POST', '/payment-methods/'.$code.'?payment='.$paymentId, server: $server, content: $body);
    }

    private function switchMethod(OrderInterface $order, PaymentMethodInterface $paymentMethod): void
    {
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $payment->setMethod($paymentMethod);
        $this->entityManager()->flush();
    }
}
