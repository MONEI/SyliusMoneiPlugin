<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Shop;

use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/** The onsite order-pay page as rendered; its monei.js script runs only in the e2e suite. */
final class OnsitePayTest extends MoneiTestCase
{
    /**
     * MONEI refuses the Complete page token unless the payment has the session ID the page gave
     * the component; the pay page finds the token in the browser under the key the Complete page used.
     */
    public function testAnInlinePaymentCarriesTheSessionIdOfTheCompletePage(): void
    {
        $this->attachCart($this->createMoneiPaymentMethod('MONEI_INLINE', ['mode' => 'onsite', 'account_id' => self::ACCOUNT_ID]));
        $complete = $this->client->request('GET', '/en_US/checkout/complete');
        $inline = $complete->filterXPath('//div[@id="monei-inline"]');
        $sessionId = (string) $inline->attr('data-session-id');
        $entryKey = (string) $inline->attr('data-entry-key');
        self::assertNotSame('', $sessionId);
        self::assertNotSame('', $entryKey);

        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));
        $this->client->followRedirects();
        $page = $this->client->submit($complete->filterXPath('//div[@id="monei-inline"]/ancestor::form')->form());
        self::assertResponseIsSuccessful();

        self::assertSame(['POST /payments'], $this->requestKeys());
        self::assertSame($sessionId, self::lastRequestBody()['sessionId'] ?? null);
        $form = $page->filterXPath('//form[@id="monei-onsite"]');
        self::assertSame([$entryKey, 'MONEI_INLINE'], [$form->attr('data-entry-key'), $form->attr('data-method-code')]);
    }

    /** Without an account ID the Complete page makes no token, so nothing may look for one. */
    public function testAnOnsitePaymentWithoutAccountIdHasNoSessionId(): void
    {
        $page = $this->payOnsite(['payment_method' => 'card']);

        self::assertArrayNotHasKey('sessionId', self::lastRequestBody());
        self::assertNull($page->filterXPath('//form[@id="monei-onsite"]')->attr('data-entry-key'));
    }

    public function testTheCardPageHasACardholderNameInputAndLoadsMoneiJs(): void
    {
        $page = $this->payOnsite(['payment_method' => 'card']);

        self::assertCount(1, $page->filterXPath('//form[@id="monei-onsite"]//input[@name="cardholder_name"]'));
        self::assertSame(['https://js.monei.com/v3/monei.js'], $page->filterXPath('//script[contains(@src, "monei")]')->extract(['src']));
    }

    /** Separate fields are the default, also for methods saved before the option existed. */
    public function testTheCardPageShowsSeparateLabelledCardFieldsByDefault(): void
    {
        $page = $this->payOnsite(['payment_method' => 'card']);

        self::assertSame('split', $page->filterXPath('//form[@id="monei-onsite"]')->attr('data-card-layout'));
        self::assertSame(
            ['monei-card-number' => 'Card number', 'monei-card-expiry' => 'Expiry date', 'monei-card-cvc' => 'CVC'],
            self::cardFields($page),
        );
    }

    public function testTheSingleCardLayoutShowsOneLabelledCardField(): void
    {
        $page = $this->payOnsite(['payment_method' => 'card', 'card_layout' => 'single']);

        self::assertSame('single', $page->filterXPath('//form[@id="monei-onsite"]')->attr('data-card-layout'));
        self::assertSame(['monei-card-input' => 'Card details'], self::cardFields($page));
    }

    public function testPayPalWithUseAuthorizePassesTransactionTypeAuth(): void
    {
        $page = $this->payOnsite(['payment_method' => 'paypal', 'use_authorize' => true]);

        self::assertSame('AUTH', $page->filterXPath('//form[@id="monei-onsite"]')->attr('data-transaction-type'));
    }

    public function testThePayPalPageHasNoElementWithTheIdMoneiPaypal(): void
    {
        $page = $this->payOnsite(['payment_method' => 'paypal']);

        self::assertSame('paypal', $page->filterXPath('//form[@id="monei-onsite"]')->attr('data-component'));
        self::assertCount(0, $page->filterXPath('//*[@id="monei-paypal"]'));
    }

    public function testThePageCarriesThePaymentIdAndTheAfterPayUrlButNotTheApiKey(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD', ['mode' => 'onsite']));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));

        $payUrl = $this->startPay($order);
        $page = $this->client->request('GET', $payUrl);

        $form = $page->filterXPath('//form[@id="monei-onsite"]');
        self::assertSame(
            ['pay_1', '/en_US/order/after-pay/'.self::hash($payUrl)],
            [$form->attr('data-payment-id'), $form->attr('data-after-pay-url')],
        );
        self::assertStringNotContainsString(self::API_KEY, (string) $this->client->getResponse()->getContent());
    }

    /** MONEI refuses a second confirm: a buyer back from an abandoned 3DS must resume it, not get the page again. */
    public function testPayingAgainOnAChallengedPaymentRedirectsToTheChallenge(): void
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_CARD', ['mode' => 'onsite']));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));
        $this->pay($order);

        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING', nextAction: ['type' => 'CHALLENGE', 'redirectUrl' => 'https://secure.monei.com/payments/pay_1/challenge']));
        $this->pay($order);

        self::assertRedirectsTo('https://secure.monei.com/payments/pay_1/challenge', Response::HTTP_SEE_OTHER);
    }

    /** @return array<string, string> the label of each card field container, by container id */
    private static function cardFields(Crawler $page): array
    {
        $fields = [];
        $page->filterXPath('//form[@id="monei-onsite"]//div[contains(concat(" ", @class, " "), " monei-card-part ")]')->each(
            static function (Crawler $container) use (&$fields): void {
                $label = $container->previousAll()->first();
                self::assertSame('label', $label->nodeName());
                $fields[(string) $container->attr('id')] = $label->text();
            },
        );

        return $fields;
    }

    /** @param array<string, mixed> $config */
    private function payOnsite(array $config): Crawler
    {
        $order = $this->placeOrder($this->createMoneiPaymentMethod('MONEI_ONSITE', ['mode' => 'onsite'] + $config));
        MoneiMockHandler::append(self::moneiPayment('pay_1', 'PENDING'));

        $page = $this->client->request('GET', $this->startPay($order));
        self::assertResponseIsSuccessful();

        return $page;
    }
}
