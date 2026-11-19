<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Admin;

use Monei\Internal\GuzzleHttp\Psr7\Response;
use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/** The admin payment method list: the MONEI logo on Sylius 2.3, the stock list on 2.2. */
final class PaymentMethodListTest extends MoneiTestCase
{
    public function testTheMoneiRowShowsTheMoneiLogoAndOtherRowsKeepTheStockCell(): void
    {
        $this->createMoneiPaymentMethod('A_MONEI');
        $this->createOfflinePaymentMethod('B_OFFLINE');
        $this->loginAdmin();
        if ($this->hasStockLogoTemplate()) {
            MoneiMockHandler::appendFor('GET /allowed-payment-methods', new Response(200, ['Content-Type' => 'application/json'], '{"paymentMethods":["card"],"metadata":{"card":{"brands":["visa","mastercard","maestro"]}}}'));
        }

        $page = $this->client->request('GET', '/admin/payment-methods/?sorting[name]=desc');
        self::assertResponseIsSuccessful();

        $moneiRow = $this->row($page, 'A_MONEI');
        if (!$this->hasStockLogoTemplate()) {
            // Sylius 2.2: the stock name cell for every method, no logos.
            self::assertSame('A_MONEI', $moneiRow->filterXPath('descendant::div['.self::cssClass('fw-medium').']')->text());
            self::assertCount(0, $page->filterXPath('//table//img'));

            return;
        }

        $logo = 'descendant::div['.self::cssClass('payment-method-list__logo').']';
        self::assertSame('/bundles/moneisyliusplugin/images/monei.svg', $moneiRow->filterXPath($logo.'/img')->attr('src'));
        // The card brands of the MONEI account.
        self::assertSame(['visa', 'master_card', 'maestro'], $moneiRow->filterXPath('descendant::span['.self::cssClass('payment-method-list__method').']/img')->each(
            static fn (Crawler $img): string => basename((string) $img->attr('src'), '.svg'),
        ));
        $offlineRow = $this->row($page, 'B_OFFLINE');
        self::assertCount(1, $offlineRow->filterXPath($logo.'/*[local-name()="svg"]'));
        self::assertCount(0, $offlineRow->filterXPath($logo.'//img'));
        // The list keeps the stock label and the sort by name with the plugin template.
        self::assertSame(['B_OFFLINE', 'A_MONEI'], $page->filterXPath('//h3['.self::cssClass('payment-method-list__name').']')->each(
            static fn (Crawler $name): string => $name->text(),
        ));
        self::assertContains('Payment method', $page->filterXPath('//table//thead//th')->each(static fn (Crawler $th): string => trim($th->text())));
    }

    /** Sylius 2.3 ships this template; 2.2 does not. */
    private function hasStockLogoTemplate(): bool
    {
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof Environment);

        return $twig->getLoader()->exists('@SyliusAdmin/payment_method/grid/field/name.html.twig');
    }

    private function row(Crawler $page, string $code): Crawler
    {
        $row = $page->filterXPath('//table//tbody/tr')->reduce(static fn (Crawler $tr): bool => str_contains($tr->text(), $code));
        self::assertCount(1, $row);

        return $row;
    }

    private static function cssClass(string $class): string
    {
        return \sprintf('contains(concat(" ", normalize-space(@class), " "), " %s ")', $class);
    }
}
