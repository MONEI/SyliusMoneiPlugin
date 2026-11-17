<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Monei\Internal\GuzzleHttp\Psr7\Response as MoneiResponse;
use Monei\Model\Payment as MoneiPayment;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Monei\SyliusPlugin\Tests\TestApplication\src\CommitPaymentStateListener;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Addressing\Model\AddressInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Base for functional tests: an empty database with one channel, one admin, one
 * product and one customer, MONEI mocked at the HTTP layer, and the `monei` log
 * channel captured. Rows are committed, never wrapped in a test transaction, so a
 * second connection sees them.
 */
abstract class MoneiTestCase extends WebTestCase
{
    public const API_KEY = 'pk_test_0123456789abcdef0123456789abcdef';
    public const CHANNEL_CODE = 'WEB';
    public const PRODUCT_PRICE = 10000;

    protected KernelBrowser $client;
    protected ChannelInterface $channel;
    protected AdminUserInterface $admin;
    protected CustomerInterface $customer;
    protected ProductVariantInterface $variant;

    private ?Connection $secondConnection = null;
    private ?int $originalLockWaitTimeout = null;

    protected function setUp(): void
    {
        parent::setUp();

        MoneiMockHandler::reset();
        MoneiLogHandler::clear();
        CommitPaymentStateListener::reset();

        $this->client = static::createClient();
        $this->purgeDatabase();
        $this->createBaseData();
    }

    protected function tearDown(): void
    {
        $unanswered = MoneiMockHandler::unanswered();
        $unused = MoneiMockHandler::unused();
        MoneiMockHandler::reset();
        MoneiLogHandler::clear();

        try {
            $this->closeSecondConnection();
        } finally {
            parent::tearDown();
        }

        // Guzzle turns an exception in the handler into a rejection that plugin code may catch,
        // so a request that found no mocked response can only fail the test here.
        if ([] !== $unanswered) {
            self::fail('MONEI requests without a mocked response: '.implode(', ', $unanswered));
        }
        if ([] !== $unused && $this->status()->isSuccess()) {
            self::fail('Mocked MONEI responses never requested: '.implode(', ', $unused));
        }
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    /**
     * @param array<string, mixed> $config merged over a valid hosted card config
     */
    protected function createMoneiPaymentMethod(string $code, array $config = []): PaymentMethodInterface
    {
        /** @var PaymentMethodInterface $method */
        $method = $this->service('sylius.factory.payment_method')->createWithGateway('monei');
        $method->setCode($code);
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName($code);
        $method->setEnabled(true);
        $method->addChannel($this->managed($this->channel));

        $gatewayConfig = $method->getGatewayConfig();
        \assert(null !== $gatewayConfig);
        $gatewayConfig->setGatewayName($code);
        $gatewayConfig->setConfig($config + [
            'api_key' => self::API_KEY,
            'payment_method' => 'card',
            'mode' => 'hosted',
            'use_authorize' => false,
        ]);
        if (method_exists($gatewayConfig, 'setUsePayum')) {
            $gatewayConfig->setUsePayum(false);
        }

        $this->entityManager()->persist($method);
        $this->entityManager()->flush();

        return $method;
    }

    protected function createOfflinePaymentMethod(string $code): PaymentMethodInterface
    {
        /** @var PaymentMethodInterface $method */
        $method = $this->service('sylius.factory.payment_method')->createWithGateway('offline');
        $method->setCode($code);
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName($code);
        $method->setEnabled(true);
        $method->addChannel($this->managed($this->channel));
        $method->getGatewayConfig()?->setGatewayName($code);

        $this->entityManager()->persist($method);
        $this->entityManager()->flush();

        return $method;
    }

    /**
     * Places an order through the Sylius checkout workflow: state `new`, payment state
     * `awaiting_payment`, number, token and checkout time set, and one `new` payment
     * with `$paymentMethod` for `$quantity` times PRODUCT_PRICE.
     */
    protected function placeOrder(PaymentMethodInterface $paymentMethod, int $quantity = 1): OrderInterface
    {
        /** @var OrderInterface $order */
        $order = $this->service('sylius.factory.order')->createNew();
        $order->setChannel($this->managed($this->channel));
        $order->setLocaleCode('en_US');
        $order->setCurrencyCode('EUR');
        $order->setCustomer($this->managed($this->customer));

        /** @var OrderItemInterface $item */
        $item = $this->service('sylius.factory.order_item')->createNew();
        $item->setVariant($this->managed($this->variant));
        $quantityModifier = $this->service('sylius.modifier.order_item_quantity');
        \assert($quantityModifier instanceof OrderItemQuantityModifierInterface);
        $quantityModifier->modify($item, $quantity);
        $orderModifier = $this->service('sylius.modifier.order');
        \assert($orderModifier instanceof OrderModifierInterface);
        $orderModifier->addToOrder($order, $item);

        $order->setBillingAddress($this->createAddress());
        $order->setShippingAddress($this->createAddress());

        $stateMachine = $this->service('sylius_abstraction.state_machine');
        \assert($stateMachine instanceof StateMachineInterface);
        $graph = OrderCheckoutTransitions::GRAPH;
        $stateMachine->apply($order, $graph, OrderCheckoutTransitions::TRANSITION_ADDRESS);
        if ($stateMachine->can($order, $graph, OrderCheckoutTransitions::TRANSITION_SKIP_SHIPPING)) {
            $stateMachine->apply($order, $graph, OrderCheckoutTransitions::TRANSITION_SKIP_SHIPPING);
        }
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $payment->setMethod($this->managed($paymentMethod));
        $stateMachine->apply($order, $graph, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        $stateMachine->apply($order, $graph, OrderCheckoutTransitions::TRANSITION_COMPLETE);

        $this->entityManager()->persist($order);
        $this->entityManager()->flush();

        return $order;
    }

    /** An order whose last payment is bound to MONEI's pay_1, authorized with a card. */
    protected function authorizedOrder(): OrderInterface
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD', ['use_authorize' => true]));
        $this->bindPayment($order, 'pay_1', 'MONEI_CARD', 'AUTH');
        $this->applyMoneiStatus($order, 'pay_1', 'AUTHORIZED');

        return $order;
    }

    /** Binds the order's last payment to a MONEI payment created with `$paymentMethodCode`. */
    protected function bindPayment(OrderInterface $order, string $moneiPaymentId, string $paymentMethodCode, string $transactionType = 'SALE'): void
    {
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        MoneiDetails::bind($payment, $moneiPaymentId, $paymentMethodCode, $transactionType);
        $this->entityManager()->flush();
    }

    /** Moves the order's last (bound) payment and the order's payment state as MONEI's `$status` does. */
    protected function applyMoneiStatus(OrderInterface $order, string $moneiPaymentId, string $status): void
    {
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $applier = $this->service('monei.test.status_applier');
        \assert($applier instanceof StatusApplier);
        $applier->apply($payment, new MoneiPayment([
            'id' => $moneiPaymentId,
            'status' => $status,
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrencyCode(),
        ]));
        $this->entityManager()->flush();
    }

    protected function loginAdmin(): void
    {
        $this->client->loginUser($this->admin, 'admin');
    }

    /**
     * The CSRF token of the admin transition routes (token id = resource id) or of the refund
     * plugin's refund form (token id = order number), stored in the browser's session: with the
     * refund plugin the page does not render the core Refund button.
     */
    protected function csrfToken(int|string $tokenId): string
    {
        $sessionFactory = $this->service('session.factory');
        \assert($sessionFactory instanceof SessionFactoryInterface);
        $session = $sessionFactory->createSession();
        $cookie = $this->client->getCookieJar()->get($session->getName());
        \assert(null !== $cookie, 'Log in first.');
        $session->setId($cookie->getValue());
        $session->start();

        $request = new Request();
        $request->setSession($session);
        $requestStack = $this->service('request_stack');
        \assert($requestStack instanceof RequestStack);
        $requestStack->push($request);
        try {
            $tokenManager = $this->service('security.csrf.token_manager');
            \assert($tokenManager instanceof CsrfTokenManagerInterface);
            $token = $tokenManager->getToken((string) $tokenId)->getValue();
        } finally {
            $requestStack->pop();
        }
        $session->save();

        return $token;
    }

    /** Follows the refusal redirect to the admin order page and checks the plugin's error text. */
    protected function assertOrderPageShows(OrderInterface $order, string $text): void
    {
        self::assertSame('/admin/orders/'.$order->getId(), $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($text, html_entity_decode($content, \ENT_QUOTES));
        self::assertStringNotContainsString('successfully', $content);
    }

    /** A JWT for the admin API, as the admin with API access. */
    protected function adminApiToken(): string
    {
        $admin = $this->managed($this->admin);
        $admin->addRole('ROLE_API_ACCESS');
        $this->entityManager()->flush();
        $jwtManager = $this->service('lexik_jwt_authentication.jwt_manager');
        \assert($jwtManager instanceof JWTTokenManagerInterface);

        return $jwtManager->create($admin);
    }

    /** Call after a request, before the next: a refusal must not leave the kernel's transaction open. */
    protected static function assertNoOpenTransaction(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    protected function orderState(OrderInterface $order): string
    {
        $state = $this->secondConnection()->fetchOne('SELECT state FROM sylius_order WHERE id = ?', [$order->getId()]);
        \assert(\is_string($state));

        return $state;
    }

    /** @return array<string, mixed> */
    protected static function lastRequestBody(): array
    {
        $history = MoneiMockHandler::history();
        $last = end($history);
        \assert(false !== $last);

        return json_decode((string) $last->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** A DBAL connection to the test database, separate from the kernel's. */
    protected function secondConnection(): Connection
    {
        return $this->secondConnection ??= DriverManager::getConnection(
            $this->entityManager()->getConnection()->getParams(),
        );
    }

    /**
     * Holds a row lock on the payment from the second connection until the test ends.
     * FOR SHARE lets a payment request insert pass its foreign-key check; FOR UPDATE does not.
     * Lock waits time out after 1 s for every session opened from now on.
     */
    protected function holdPaymentRowLock(PaymentInterface $payment, string $mode = 'FOR SHARE'): void
    {
        \assert(\in_array($mode, ['FOR SHARE', 'FOR UPDATE'], true));
        $connection = $this->secondConnection();

        if (null === $this->originalLockWaitTimeout) {
            $this->originalLockWaitTimeout = (int) $connection->fetchOne('SELECT @@GLOBAL.innodb_lock_wait_timeout');
            // Global, not session: the kernel reboots between requests and opens a new session.
            $connection->executeStatement('SET GLOBAL innodb_lock_wait_timeout = 1');
        }
        // The kernel's open session still has the old timeout; it reconnects on next use.
        $this->entityManager()->getConnection()->close();

        $connection->beginTransaction();
        $connection->fetchOne('SELECT id FROM sylius_payment WHERE id = ? '.$mode, [$payment->getId()]);
    }

    /** @return list<array{level: \Monolog\Level, message: string, text: string}> records of the `monei` channel */
    protected function moneiLogs(): array
    {
        return MoneiLogHandler::records();
    }

    /** Opens the order pay page; returns the `/pay/{hash}` path Sylius redirects to. */
    protected function startPay(OrderInterface $order): string
    {
        $this->client->request('GET', '/en_US/order/'.$order->getTokenValue().'/pay');
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/en_US/payment-request/pay/[0-9a-f-]{36}$#', $location);

        return $location;
    }

    protected function pay(OrderInterface $order): void
    {
        $this->client->request('GET', $this->startPay($order));
    }

    protected static function hash(string $payUrl): string
    {
        return substr($payUrl, -36);
    }

    protected static function assertRedirectsTo(string $url, int $status): void
    {
        $response = self::getClient()?->getResponse();
        \assert($response instanceof Response);
        self::assertSame([$status, $url], [$response->getStatusCode(), $response->headers->get('Location')]);
    }

    protected function paymentId(OrderInterface $order): int
    {
        $payment = $order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $id = $payment->getId();
        \assert(\is_int($id));

        return $id;
    }

    /** Read by id: a failed payment makes Sylius add a new payment to the order. */
    protected function paymentState(OrderInterface $order): string
    {
        $state = $this->secondConnection()->fetchOne('SELECT state FROM sylius_payment WHERE id = ?', [$this->paymentId($order)]);
        \assert(\is_string($state));

        return $state;
    }

    /** @return list<array{string, string}> action and state of each payment request of the order's payment */
    protected function paymentRequests(OrderInterface $order): array
    {
        /** @var list<array{action: string, state: string}> $rows */
        $rows = $this->secondConnection()->fetchAllAssociative('SELECT action, state FROM sylius_payment_request WHERE payment_id = ?', [$this->paymentId($order)]);

        return array_map(static fn (array $row): array => [$row['action'], $row['state']], $rows);
    }

    /** @return list<string> */
    protected function requestKeys(): array
    {
        return array_map(MoneiMockHandler::key(...), MoneiMockHandler::history());
    }

    /**
     * @param array<string, string>|null $nextAction defaults to CONFIRM on the MONEI payment page for PENDING
     * @param array<string, mixed>       $extra
     */
    protected static function moneiPayment(string $id, string $status, int $amount = self::PRODUCT_PRICE, ?array $nextAction = null, array $extra = []): MoneiResponse
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

    protected static function error(int $statusCode, string $message): MoneiResponse
    {
        return new MoneiResponse($statusCode, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'ERROR',
            'statusCode' => $statusCode,
            'message' => $message,
            'requestId' => 'req_test',
        ], \JSON_THROW_ON_ERROR));
    }

    private function closeSecondConnection(): void
    {
        if (null === $this->secondConnection) {
            return;
        }

        $connection = $this->secondConnection;
        $this->secondConnection = null;
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        if (null !== $this->originalLockWaitTimeout) {
            $connection->executeStatement(\sprintf('SET GLOBAL innodb_lock_wait_timeout = %d', $this->originalLockWaitTimeout));
            $this->originalLockWaitTimeout = null;
        }
        $connection->close();
    }

    /**
     * The entity as known to the current entity manager: KernelBrowser reboots the
     * kernel between requests, which detaches the fixtures created in setUp.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function managed(object $entity): object
    {
        $entityManager = $this->entityManager();
        if ($entityManager->contains($entity)) {
            return $entity;
        }

        $managed = $entityManager->find($entity::class, $entityManager->getClassMetadata($entity::class)->getIdentifierValues($entity));
        \assert($managed instanceof $entity);

        return $managed;
    }

    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert(\is_object($service));

        return $service;
    }

    private function purgeDatabase(): void
    {
        $connection = $this->entityManager()->getConnection();
        $tables = $connection->fetchFirstColumn('SHOW TABLES');

        // DELETE, not TRUNCATE: much faster on empty tables. FK checks off so the order does not matter.
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $connection->executeStatement(\sprintf('DELETE FROM `%s`', $table));
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->entityManager()->clear();
    }

    private function createBaseData(): void
    {
        $entityManager = $this->entityManager();

        /** @var LocaleInterface $locale */
        $locale = $this->service('sylius.factory.locale')->createNew();
        $locale->setCode('en_US');
        $entityManager->persist($locale);

        /** @var CurrencyInterface $currency */
        $currency = $this->service('sylius.factory.currency')->createNew();
        $currency->setCode('EUR');
        $entityManager->persist($currency);

        /** @var ChannelInterface $channel */
        $channel = $this->service('sylius.factory.channel')->createNew();
        $channel->setCode(self::CHANNEL_CODE);
        $channel->setName('Web Store');
        $channel->setHostname('localhost');
        $channel->setEnabled(true);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setTaxCalculationStrategy('order_items_based');
        $entityManager->persist($channel);
        $this->channel = $channel;

        /** @var AdminUserInterface $admin */
        $admin = $this->service('sylius.factory.admin_user')->createNew();
        $admin->setUsername('admin');
        $admin->setEmail('admin@example.com');
        $admin->setPlainPassword('admin');
        $admin->setLocaleCode('en_US');
        $admin->setEnabled(true);
        $entityManager->persist($admin);
        $this->admin = $admin;

        /** @var CustomerInterface $customer */
        $customer = $this->service('sylius.factory.customer')->createNew();
        $customer->setEmail('buyer@example.com');
        $customer->setFirstName('Ana');
        $customer->setLastName('García');
        $entityManager->persist($customer);
        $this->customer = $customer;

        /** @var ProductInterface $product */
        $product = $this->service('sylius.factory.product')->createWithVariant();
        $product->setCode('MONEI_MUG');
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Mug');
        $product->setSlug('mug');
        $product->addChannel($channel);

        $variant = $product->getVariants()->first();
        \assert($variant instanceof ProductVariantInterface);
        $variant->setCode('MONEI_MUG');
        $variant->setShippingRequired(false);
        $variant->setTracked(false);

        /** @var ChannelPricingInterface $channelPricing */
        $channelPricing = $this->service('sylius.factory.channel_pricing')->createNew();
        $channelPricing->setChannelCode(self::CHANNEL_CODE);
        $channelPricing->setPrice(self::PRODUCT_PRICE);
        $variant->addChannelPricing($channelPricing);
        $entityManager->persist($product);
        $this->variant = $variant;

        $entityManager->flush();
    }

    private function createAddress(): AddressInterface
    {
        /** @var AddressInterface $address */
        $address = $this->service('sylius.factory.address')->createNew();
        $address->setFirstName('Ana');
        $address->setLastName('García');
        $address->setStreet('Calle Mayor 1');
        $address->setCity('Málaga');
        $address->setPostcode('29001');
        $address->setCountryCode('ES');

        return $address;
    }
}
