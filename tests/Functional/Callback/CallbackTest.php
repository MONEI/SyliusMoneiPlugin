<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Callback;

use Monei\Internal\Psr\Http\Message\RequestInterface;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Monolog\Level;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\HttpFoundation\Response;

/** MONEI callbacks on `/payment-methods/{code}?payment=<id>`. MONEI is mocked. */
final class CallbackTest extends MoneiTestCase
{
    private const OTHER_API_KEY = 'pk_test_fedcba9876543210fedcba9876543210';

    private const BODY = '{"id":"pay_1","status":"SUCCEEDED","amount":10000,"currency":"EUR"}';

    /** HMAC-SHA256 of "1763300000.".BODY with MoneiTestCase::API_KEY, computed with Python's hmac. */
    private const SIGNATURE = 't=1763300000,v1=092ddaa9ac309f99014c5c05b119651c9b943e8d1fcfb5e401e5385b64fe6c6f';

    public function testAnUnsignedCallbackIsRefusedWithoutAMoneiCall(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_1', 'MONEI_CARD');

        $this->postCallback('MONEI_CARD', $this->paymentId($order), null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
    }

    public function testASignedSucceededCallbackCompletesThePayment(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_1', 'MONEI_CARD');
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
        $this->bind($order, 'pay_2', 'MONEI_CARD_B');
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
        $this->bind($order, 'pay_1', 'MONEI_CARD');
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
        $this->bind($order, 'pay_1', 'MONEI_CARD');
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
        $this->bind($order, 'pay_2', 'MONEI_CARD');
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED'));

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
        self::assertContains(Level::Critical, array_column($this->moneiLogs(), 'level'));
    }

    public function testAMonei500OnTheFetchAnswers5xxSoMoneiRetries(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_1', 'MONEI_CARD');
        MoneiMockHandler::append(self::error(500, 'Internal server error'));

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertGreaterThanOrEqual(500, $this->client->getResponse()->getStatusCode());
        self::assertSame('new', $this->paymentState($order));
    }

    /** The notify request stays open after the 5xx; its replay route merges any body into the payload. */
    public function testAnUnsignedReplayOfTheOpenNotifyRequestChangesNothing(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->bind($order, 'pay_1', 'MONEI_CARD');
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
        $this->bind($order, 'pay_1', 'MONEI_CARD');
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $this->holdPaymentRowLock($payment);

        $this->postCallback('MONEI_CARD', $this->paymentId($order));

        self::assertGreaterThanOrEqual(500, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
    }

    private function postCallback(string $code, int $paymentId, ?string $signature = self::SIGNATURE): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $signature) {
            $server['HTTP_MONEI_SIGNATURE'] = $signature;
        }

        $this->client->request('POST', '/payment-methods/'.$code.'?payment='.$paymentId, server: $server, content: self::BODY);
    }

    private function bind(OrderInterface $order, string $moneiPaymentId, string $paymentMethodCode): void
    {
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        MoneiDetails::bind($payment, $moneiPaymentId, $paymentMethodCode);
        $this->entityManager()->flush();
    }

    private function switchMethod(OrderInterface $order, PaymentMethodInterface $paymentMethod): void
    {
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $payment->setMethod($paymentMethod);
        $this->entityManager()->flush();
    }

    private function createOfflinePaymentMethod(string $code): PaymentMethodInterface
    {
        $method = self::getContainer()->get('sylius.factory.payment_method')->createWithGateway('offline');
        \assert($method instanceof PaymentMethodInterface);
        $method->setCode($code);
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName($code);
        $method->setEnabled(true);
        $method->addChannel($this->channel);
        $method->getGatewayConfig()?->setGatewayName($code);

        $this->entityManager()->persist($method);
        $this->entityManager()->flush();

        return $method;
    }
}
