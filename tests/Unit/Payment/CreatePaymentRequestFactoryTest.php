<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Payment;

use Monei\Model\CreatePaymentRequest;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Payment\CreatePaymentRequestFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

final class CreatePaymentRequestFactoryTest extends TestCase
{
    public function testAmountIsSentInMinorUnits(): void
    {
        $request = self::create(amount: 1999);

        self::assertSame(1999, $request->getAmount());
        self::assertSame('EUR', $request->getCurrency());
    }

    /** JPY has no decimals: MONEI would read the Sylius amount 100 times too high. */
    public function testCurrencyWithoutTwoDecimalsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"JPY"');

        self::create(currency: 'JPY');
    }

    public function testWithoutUseAuthorizeSendsSale(): void
    {
        self::assertSame('SALE', self::create()->getTransactionType());
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('orderIds')]
    public function testOrderId(array $config, string $expected): void
    {
        self::assertSame($expected, self::create(config: $config, orderNumber: '000000123', paymentId: 45)->getOrderId());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function orderIds(): iterable
    {
        yield 'card with prefix' => [['order_id_prefix' => 'shop2-'], 'shop2-000000123'];
        yield 'multibanco is unique per attempt' => [['payment_method' => 'multibanco', 'order_id_prefix' => 'shop2-'], 'shop2-000000123-45'];
    }

    public function testOrderNumberMoneiRefusesIsRefusedBeforeAnyCall(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('order ID "000 123"');

        self::create(orderNumber: '000 123');
    }

    /** @param list<string> $expected */
    #[DataProvider('allowedPaymentMethods')]
    public function testAllowedPaymentMethods(string $method, array $expected): void
    {
        self::assertSame($expected, self::create(config: ['payment_method' => $method])->getAllowedPaymentMethods());
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function allowedPaymentMethods(): iterable
    {
        yield 'card' => ['card', ['card']];
        yield 'bizum' => ['bizum', ['bizum']];
        yield 'paypal' => ['paypal', ['paypal']];
        yield 'apple pay / google pay' => ['apple_google_pay', ['applePay', 'googlePay']];
        yield 'mb way' => ['mbway', ['mbway']];
        yield 'multibanco' => ['multibanco', ['multibanco']];
    }

    /** MONEI expires a PENDING payment after 7 days at most; a longer order expiry gets that limit. */
    public function testExpireAtIsAtMostSevenDaysFromNow(): void
    {
        $order = new Order();
        $order->setNumber('000000001');
        $order->setCheckoutCompletedAt(new \DateTime('@1767225600'));
        $payment = new Payment();
        $payment->setAmount(1000);
        $payment->setCurrencyCode('EUR');
        $order->addPayment($payment);

        $request = (new CreatePaymentRequestFactory(new RequestStack(), new MockClock('@1767225600'), '30 days'))->create(
            $payment,
            GatewayConfiguration::fromArray(['api_key' => 'pk_test_0123456789abcdef', 'payment_method' => 'card']),
            'https://shop.test/after-pay',
            'https://shop.test/callback',
        );

        self::assertSame(1767830400, $request->getExpireAt());
    }

    /** @param array<string, mixed> $config */
    private static function create(
        array $config = [],
        int $amount = 1000,
        string $currency = 'EUR',
        string $orderNumber = '000000001',
        int $paymentId = 1,
    ): CreatePaymentRequest {
        $order = new Order();
        $order->setNumber($orderNumber);

        $payment = new Payment();
        $payment->setAmount($amount);
        $payment->setCurrencyCode($currency);
        (new \ReflectionProperty(Payment::class, 'id'))->setValue($payment, $paymentId);
        $order->addPayment($payment);

        $configuration = GatewayConfiguration::fromArray($config + [
            'api_key' => 'pk_test_0123456789abcdef',
            'payment_method' => 'card',
            'mode' => 'hosted',
        ]);

        return (new CreatePaymentRequestFactory(new RequestStack(), new MockClock(), '5 days'))
            ->create($payment, $configuration, 'https://shop.test/after-pay', 'https://shop.test/callback');
    }
}
