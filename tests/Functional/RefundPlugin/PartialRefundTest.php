<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\RefundPlugin;

use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/** Refunds through sylius/refund-plugin of an order of two units paid with MONEI. */
final class PartialRefundTest extends MoneiTestCase
{
    private OrderInterface $order;
    private PaymentMethodInterface $moneiMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moneiMethod = $this->createMoneiPaymentMethod('MONEI_CARD');
        $this->order = $this->placeOrder($this->moneiMethod, 2);
        $this->bindPayment($this->order, 'pay_1', 'MONEI_CARD');
        $this->applyMoneiStatus($this->order, 'pay_1', 'SUCCEEDED');
        $this->loginAdmin();
    }

    public function testTheRefundFormOffersTheMoneiPaymentMethod(): void
    {
        $crawler = $this->client->request('GET', '/admin/orders/'.$this->order->getNumber().'/refunds');

        self::assertResponseIsSuccessful();
        self::assertSame(['MONEI_CARD'], $crawler->filterXPath('//select[@name="sylius_refund_payment_method"]/option')->each(
            static fn ($option): string => trim($option->text()),
        ));
    }

    public function testRefundingOneUnitAsksMoneiForThatUnitsPrice(): void
    {
        MoneiMockHandler::append(
            self::moneiPayment('pay_1', 'SUCCEEDED', 20000),
            self::moneiPayment('pay_1', 'PARTIALLY_REFUNDED', 20000, extra: ['refundedAmount' => 10000]),
        );

        $this->refundUnit(0, $this->moneiMethod);

        self::assertSame(['GET /payments/pay_1', 'POST /payments/pay_1/refund'], $this->requestKeys());
        self::assertSame(10000, self::lastRequestBody()['amount']);
        self::assertSame([10000], $this->refundPayments());
    }

    /** The recorded total counts earlier refunds: otherwise every second partial refund is refused. */
    public function testASecondPartialRefundCountsTheFirstOneAsRecorded(): void
    {
        MoneiMockHandler::append(
            self::moneiPayment('pay_1', 'SUCCEEDED', 20000),
            self::moneiPayment('pay_1', 'PARTIALLY_REFUNDED', 20000, extra: ['refundedAmount' => 10000]),
            self::moneiPayment('pay_1', 'PARTIALLY_REFUNDED', 20000, extra: ['refundedAmount' => 10000]),
            self::moneiPayment('pay_1', 'REFUNDED', 20000, extra: ['refundedAmount' => 20000]),
        );

        $this->refundUnit(0, $this->moneiMethod);
        $this->refundUnit(1, $this->moneiMethod);

        self::assertSame(10000, self::lastRequestBody()['amount']);
        self::assertSame([10000, 10000], $this->refundPayments());
    }

    /** MONEI answers a refund equal to one sent less than 3 s before with that refund's answer, and refunds nothing. */
    public function testARefundMoneiDidNotApplyIsNotRecorded(): void
    {
        MoneiMockHandler::append(
            self::moneiPayment('pay_1', 'SUCCEEDED', 20000),
            self::moneiPayment('pay_1', 'PARTIALLY_REFUNDED', 20000, extra: ['refundedAmount' => 10000]),
            self::moneiPayment('pay_1', 'PARTIALLY_REFUNDED', 20000, extra: ['refundedAmount' => 10000]),
            self::moneiPayment('pay_1', 'PARTIALLY_REFUNDED', 20000, extra: ['refundedAmount' => 10000]),
        );

        $this->refundUnit(0, $this->moneiMethod);
        $this->refundUnit(1, $this->moneiMethod);

        self::assertSame([10000], $this->refundPayments());
        $this->assertRefundsPageShows('MONEI did not apply this refund');
    }

    public function testRefundingAUnitWithAnOfflineMethodMakesNoMoneiCall(): void
    {
        $this->refundUnit(0, $this->createOfflinePaymentMethod('BANK_TRANSFER'));

        self::assertSame([], $this->requestKeys());
        self::assertSame([10000], $this->refundPayments());
    }

    /** A MONEI method that did not pay the order must not refund another payment. */
    public function testRefundingWithAMoneiMethodThatDidNotPayTheOrderIsRefused(): void
    {
        $this->refundUnit(0, $this->createMoneiPaymentMethod('MONEI_BIZUM', ['payment_method' => 'bizum']));

        self::assertSame([], $this->requestKeys());
        $this->assertNothingRecorded();
        $this->assertRefundsPageShows('No payment of this order was paid with MONEI_BIZUM through MONEI.');
    }

    public function testAMoneiRefusalLeavesNoRefundRecorded(): void
    {
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'SUCCEEDED', 20000), self::error(400, 'The payment cannot be refunded'));

        $this->refundUnit(0, $this->moneiMethod);

        self::assertSame(['GET /payments/pay_1', 'POST /payments/pay_1/refund'], $this->requestKeys());
        $this->assertNothingRecorded();
        $this->assertRefundsPageShows('MONEI did not refund the payment (The payment cannot be refunded).');
    }

    /** A refund whose answer was lost: a retry would return the money twice. */
    public function testARefundIsRefusedWhenMoneiRefundedMoreThanSyliusRecorded(): void
    {
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PARTIALLY_REFUNDED', 20000, extra: ['refundedAmount' => 10000]));

        $this->refundUnit(0, $this->moneiMethod);

        self::assertSame(['GET /payments/pay_1'], $this->requestKeys());
        $this->assertNothingRecorded();
        $this->assertRefundsPageShows('MONEI has already refunded more of this payment than Sylius has recorded');
    }

    /** Two refunds at once (a double click) would both read refundedAmount 0 and refund the unit twice. */
    public function testTheRefundWaitsForThePaymentRowLock(): void
    {
        $payment = $this->order->getLastPayment();
        \assert($payment instanceof PaymentInterface);
        $this->holdPaymentRowLock($payment);

        $this->refundUnit(0, $this->moneiMethod);

        self::assertSame([], MoneiMockHandler::history());
    }

    private function refundUnit(int $index, PaymentMethodInterface $refundMethod): void
    {
        $number = (string) $this->order->getNumber();
        $this->client->request('POST', '/admin/orders/'.$number.'/refund-units', [
            'sylius_refund_units' => [$this->unitIds()[$index] => ['full' => 'on']],
            'sylius_refund_payment_method' => $refundMethod->getId(),
            '_csrf_token' => $this->csrfToken($number),
        ]);

        self::assertResponseRedirects('/admin/orders/'.$number.'/refunds');
        self::assertNoOpenTransaction();
    }

    /** @return list<int> */
    private function unitIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', $this->secondConnection()->fetchFirstColumn(
            'SELECT u.id FROM sylius_order_item_unit u JOIN sylius_order_item i ON u.order_item_id = i.id WHERE i.order_id = ? ORDER BY u.id',
            [$this->order->getId()],
        ));

        return $ids;
    }

    /** @return list<int> amounts of the order's refund payments */
    private function refundPayments(): array
    {
        return array_map('intval', $this->secondConnection()->fetchFirstColumn(
            'SELECT amount FROM sylius_refund_refund_payment WHERE order_id = ? ORDER BY id',
            [$this->order->getId()],
        ));
    }

    /**
     * The refund plugin writes the unit refunds and the order's payment state before the MONEI
     * step; the credit memo e-mail would come before it if the step ran after the credit memo.
     */
    private function assertNothingRecorded(): void
    {
        self::assertEmailCount(0);
        $connection = $this->secondConnection();
        $orderId = $this->order->getId();
        self::assertSame(
            [0, 0, 0, 'paid'],
            [
                (int) $connection->fetchOne('SELECT COUNT(*) FROM sylius_refund_refund WHERE order_id = ?', [$orderId]),
                (int) $connection->fetchOne('SELECT COUNT(*) FROM sylius_refund_credit_memo WHERE order_id = ?', [$orderId]),
                \count($this->refundPayments()),
                $connection->fetchOne('SELECT payment_state FROM sylius_order WHERE id = ?', [$orderId]),
            ],
        );
    }

    private function assertRefundsPageShows(string $text): void
    {
        $this->client->followRedirect();
        self::assertStringContainsString($text, html_entity_decode((string) $this->client->getResponse()->getContent(), \ENT_QUOTES));
    }
}
