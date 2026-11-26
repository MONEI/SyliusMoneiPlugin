<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Shop;

use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** The monei.js component on the checkout Complete page as rendered; its script runs only in the e2e suite. */
final class CompletePageTest extends MoneiTestCase
{
    public function testAnInlineCardMethodRendersTheCardFieldsInsideTheCheckoutForm(): void
    {
        $this->attachCart($this->createMoneiPaymentMethod('MONEI_INLINE', ['mode' => 'onsite', 'account_id' => self::ACCOUNT_ID]));

        $page = $this->completePage();

        $component = $page->filterXPath('//form//div[@id="monei-inline"]');
        self::assertCount(1, $component);
        self::assertSame(self::ACCOUNT_ID, $component->attr('data-account-id'));
        self::assertSame('MONEI_INLINE', $component->attr('data-method-code'));
        // MONEI refuses a payment whose session ID breaks this format.
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,40}$/D', (string) $component->attr('data-session-id'));
        self::assertCount(3, $component->filterXPath('descendant::div[@id="monei-card-number" or @id="monei-card-expiry" or @id="monei-card-cvc"]'));
        // The cardholder name goes to MONEI from the browser; with a name it would post to Sylius with the order.
        $cardholder = $component->filterXPath('descendant::input[@id="monei-cardholder-name"]');
        self::assertCount(1, $cardholder);
        self::assertNull($cardholder->attr('name'));
        self::assertStringNotContainsString(self::API_KEY, (string) $this->client->getResponse()->getContent());
    }

    /** The admin form refuses it, but a value stored before that or by hand must never reach the page. */
    public function testAStoredApiKeyAsAccountIdRendersNoComponent(): void
    {
        $this->attachCart($this->createMoneiPaymentMethod('MONEI_ONSITE', ['mode' => 'onsite', 'account_id' => self::API_KEY]));

        $page = $this->completePage();

        self::assertCount(0, $page->filterXPath('//*[@id="monei-inline"]'));
        self::assertStringNotContainsString('js.monei.com', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString(self::API_KEY, (string) $this->client->getResponse()->getContent());
    }

    /** MONEI refuses a button token whose amount, currency or transaction type differs from the payment's. */
    public function testAnInlinePayPalMethodRendersTheButtonWithThePaymentAmount(): void
    {
        $this->attachCart($this->createMoneiPaymentMethod('MONEI_PAYPAL', ['payment_method' => 'paypal', 'mode' => 'onsite', 'use_authorize' => true, 'account_id' => self::ACCOUNT_ID]), 2);

        $component = $this->completePage()->filterXPath('//form//div[@id="monei-inline"]');

        self::assertSame(
            ['paypal', '20000', 'EUR', 'AUTH'],
            [$component->attr('data-component'), $component->attr('data-amount'), $component->attr('data-currency'), $component->attr('data-transaction-type')],
        );
        self::assertCount(1, $component->filterXPath('descendant::div[@id="monei-component"]'));
        self::assertCount(0, $component->filterXPath('descendant::input[@id="monei-cardholder-name"]'));
    }

    /** The pay page refuses to create a JPY payment: the buyer must not approve a PayPal token first. */
    public function testACurrencyWithoutTwoDecimalsRendersNoComponent(): void
    {
        $cart = $this->attachCart($this->createMoneiPaymentMethod('MONEI_PAYPAL', ['payment_method' => 'paypal', 'mode' => 'onsite', 'account_id' => self::ACCOUNT_ID]));
        $payment = $cart->getLastPayment();
        \assert(null !== $payment);
        $this->secondConnection()->executeStatement("UPDATE sylius_order SET currency_code = 'JPY' WHERE id = ?", [$cart->getId()]);
        $this->secondConnection()->executeStatement("UPDATE sylius_payment SET currency_code = 'JPY' WHERE id = ?", [$payment->getId()]);
        $this->entityManager()->clear();

        self::assertCount(0, $this->completePage()->filterXPath('//*[@id="monei-inline"]'));
    }

    public function testDisconnectAfterMethodSelectionRendersTheCompletePageWithoutTheComponent(): void
    {
        $this->attachCart($this->createConnectedTestMethod(['mode' => 'onsite']));
        $this->disconnectMonei();

        self::assertCount(0, $this->completePage()->filterXPath('//*[@id="monei-inline"]'));
    }

    /** Hosted mode (and with it MB WAY and Multibanco) keeps the redirect after Place order. */
    public function testAHostedMethodWithAnAccountIdRendersNoComponent(): void
    {
        $this->attachCart($this->createMoneiPaymentMethod('MONEI_CARD', ['account_id' => self::ACCOUNT_ID]));

        self::assertCount(0, $this->completePage()->filterXPath('//*[@id="monei-inline"]'));
    }

    /** The hook runs on every checkout: a non-MONEI method must not break the page. */
    public function testAnOfflineMethodRendersTheCompletePageWithoutTheComponent(): void
    {
        $this->attachCart($this->createOfflinePaymentMethod('OFFLINE'));

        self::assertCount(0, $this->completePage()->filterXPath('//*[@id="monei-inline"]'));
    }

    private function completePage(): Crawler
    {
        $crawler = $this->client->request('GET', '/en_US/checkout/complete');
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
