<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Shop;

use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Method logos and the test mode badge on the checkout Payment step. */
final class PaymentStepLogosTest extends MoneiTestCase
{
    public function testABizumMethodShowsItsLogoAndTheRadioIsStillNamedByTheMethodName(): void
    {
        $bizum = $this->createMoneiPaymentMethod('MONEI_BIZUM', ['payment_method' => 'bizum']);
        $this->attachCart($bizum);

        $row = $this->paymentRow($this->selectPaymentPage(), 'MONEI_BIZUM');

        // The plugin replaces the stock `name` hookable: a second one would render a second radio.
        self::assertCount(1, $row->filterXPath('descendant::input[@type="radio"]'));
        self::assertSame(['/bundles/moneisyliusplugin/images/bizum.svg'], $row->filterXPath('descendant::img')->each(static fn (Crawler $img): string => (string) $img->attr('src')));
        // e2e picks methods with getByLabel(name, {exact: true}): the logos and the badge must not join the name.
        self::assertSame('MONEI_BIZUM', self::accessibleText($row));
    }

    /** PayPal's wordmark is wide and short: at the card box height it looked smaller than the Bizum wordmark. */
    public function testAPayPalMethodShowsThePayPalWordmarkAtTheWordmarkSize(): void
    {
        $this->attachCart($this->createMoneiPaymentMethod('MONEI_PAYPAL', ['payment_method' => 'paypal']));

        $img = $this->paymentRow($this->selectPaymentPage(), 'MONEI_PAYPAL')->filterXPath('descendant::img');

        self::assertSame(['/bundles/moneisyliusplugin/images/paypal.svg'], $img->each(static fn (Crawler $img): string => (string) $img->attr('src')));
        self::assertSame('monei-wordmark', $img->attr('class'));
        self::assertStringContainsString('height: 28px', (string) $img->attr('style'));
    }

    public function testATestKeyMethodShowsTheTestModeBadgeAndALiveKeyMethodDoesNot(): void
    {
        $test = $this->createMoneiPaymentMethod('MONEI_TEST');
        $this->createMoneiPaymentMethod('MONEI_LIVE', ['api_key' => 'pk_live_0123456789abcdef']);
        $this->attachCart($test);

        $page = $this->selectPaymentPage();

        $badge = 'descendant::*[contains(concat(" ", normalize-space(@class), " "), " monei-test-mode-badge ")]';
        self::assertCount(1, $this->paymentRow($page, 'MONEI_TEST')->filterXPath($badge));
        self::assertCount(0, $this->paymentRow($page, 'MONEI_LIVE')->filterXPath($badge));
    }

    public function testACorruptManualConfigurationDoesNotOfferAnUnpayableMethod(): void
    {
        $this->attachCart($this->createMoneiPaymentMethod('MONEI_CARD'));
        $this->createMoneiPaymentMethod('MONEI_BROKEN', ['payment_method' => 'nope']);

        self::assertCount(0, $this->selectPaymentPage()->filterXPath('//input[@value="MONEI_BROKEN"]'));
    }

    private function selectPaymentPage(): Crawler
    {
        $crawler = $this->client->request('GET', '/en_US/checkout/select-payment');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** The `<label>` around the radio, as Sylius renders each method. */
    private function paymentRow(Crawler $page, string $code): Crawler
    {
        $row = $page->filterXPath(\sprintf('//input[@type="radio"][@value="%s"]/ancestor::label', $code));
        self::assertCount(1, $row);

        return $row;
    }

    /** Text of the node without its aria-hidden parts. */
    private static function accessibleText(Crawler $node): string
    {
        $element = $node->getNode(0)?->cloneNode(true);
        \assert($element instanceof \DOMElement);
        $document = new \DOMDocument();
        $element = $document->importNode($element, true);
        $document->appendChild($element);
        foreach (iterator_to_array((new \DOMXPath($document))->query('//*[@aria-hidden="true"]') ?: []) as $hidden) {
            $hidden->parentNode?->removeChild($hidden);
        }

        return trim((string) preg_replace('/\s+/', ' ', $document->textContent));
    }
}
