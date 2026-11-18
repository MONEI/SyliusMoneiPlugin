<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Shop;

use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** The monei.js component on the checkout Complete page as rendered; its script runs only in the e2e suite. */
final class CompletePageTest extends MoneiTestCase
{
    private const ACCOUNT_ID = '0b1e7c2a-3d4f-4a5b-8c6d-7e8f9a0b1c2d';

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
        self::assertCount(0, $page->filterXPath('//script[contains(@src, "monei")]'));
        self::assertStringNotContainsString(self::API_KEY, (string) $this->client->getResponse()->getContent());
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
