<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Shop;

use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Payment\Provider\OrderPaymentProviderInterface;
use Symfony\Component\HttpFoundation\Response;

final class PaymentMethodAvailabilityTest extends MoneiTestCase
{
    public function testDisconnectRemovesTheHighestPriorityMethodFromCheckoutAndNewCartPayments(): void
    {
        $connected = $this->createConnectedTestMethod();
        $manual = $this->createMoneiPaymentMethod('MONEI_MANUAL');
        $connected->setPosition(0);
        $manual->setPosition(1);
        $this->entityManager()->flush();

        $cart = $this->attachCart($manual);
        $cart->setTokenValue('availability-cart');
        $this->entityManager()->flush();
        $provider = self::getContainer()->get(OrderPaymentProviderInterface::class);
        self::assertSame('MONEI_CONNECTED', $provider->provideOrderPayment($cart, PaymentInterface::STATE_CART)->getMethod()?->getCode());

        $this->disconnectMonei();

        self::assertSame('MONEI_MANUAL', $provider->provideOrderPayment($cart, PaymentInterface::STATE_CART)->getMethod()?->getCode());
        $page = $this->client->request('GET', '/en_US/checkout/select-payment');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filterXPath('//input[@value="MONEI_CONNECTED"]'));
        self::assertCount(1, $page->filterXPath('//input[@value="MONEI_MANUAL"]'));

        $this->client->request('GET', '/api/v2/shop/orders/availability-cart/payments/'.$this->paymentId($cart).'/methods', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('MONEI_CONNECTED', $content);
        self::assertStringContainsString('MONEI_MANUAL', $content);
    }

    #[DataProvider('payModes')]
    public function testAStalePayRequestCannotSendTheBuyerToAnUnsettleablePayment(string $mode, bool $onsite): void
    {
        $order = $this->placeOrder($this->createConnectedTestMethod(['mode' => $mode]));
        $this->createMoneiPaymentMethod('MONEI_MANUAL');
        $payUrl = $this->startPay($order);
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING', nextAction: $onsite ? ['type' => 'CONFIRM'] : null));
        $page = $this->client->request('GET', $payUrl);
        if ($onsite) {
            self::assertCount(1, $page->filterXPath('//*[@id="monei-onsite"]'));
        } else {
            self::assertRedirectsTo('https://secure.monei.com/payments/pay_1', Response::HTTP_SEE_OTHER);
        }

        $this->disconnectMonei();
        $this->client->request('GET', $payUrl);

        self::assertRedirectsTo('/en_US/order/'.$order->getTokenValue(), Response::HTTP_SEE_OTHER);
        self::assertSame(['POST /payments'], $this->requestKeys());
        self::assertSame('new', $this->paymentState($order));
        $page = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('This payment method is currently unavailable. Choose another payment method.', $page->text());
        self::assertCount(0, $page->filterXPath('//input[@value="MONEI_CONNECTED"]'));
        self::assertCount(1, $page->filterXPath('//input[@value="MONEI_MANUAL"]'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function payModes(): iterable
    {
        yield 'hosted redirect' => ['hosted', false];
        yield 'onsite form' => ['onsite', true];
    }
}
