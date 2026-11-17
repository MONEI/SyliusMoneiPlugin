<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\ApplePay;

use Monei\Internal\GuzzleHttp\Psr7\Response as MoneiResponse;
use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\DomCrawler\Form;

final class ApplePayTest extends MoneiTestCase
{
    private const FORM = 'sylius_admin_payment_method';
    private const CONFIG = self::FORM.'[gatewayConfig][config]';
    private const REGISTER = 'POST /apple-pay/domains';

    public function testTheDomainAssociationFileIsServedWithoutRedirect(): void
    {
        $this->client->request('GET', '/.well-known/apple-developer-merchantid-domain-association');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($response->headers->get('Location'));
        // sha256 of the file downloaded from assets.monei.com.
        self::assertSame('c61972502afc28bdeb4e78116938306e286ed683a9df590f2a620a6124d7f410', hash('sha256', (string) $response->getContent()));
    }

    public function testCreatingAnApplePayMethodRegistersTheChannelHostname(): void
    {
        $this->setChannelHostname('shop.example.com');
        MoneiMockHandler::appendFor(self::REGISTER, self::registered());
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/payment-methods/new/monei');
        $form = $crawler->filterXPath('//form[@name="'.self::FORM.'"]')->form();
        $form[self::FORM.'[code]'] = 'MONEI_WALLETS';
        $form[self::FORM.'[translations][en_US][name]'] = 'Wallets';
        $form[self::FORM.'[channels][0]']->tick(); // the only channel
        $form[self::CONFIG.'[api_key]'] = self::API_KEY;
        $form[self::CONFIG.'[payment_method]'] = 'apple_google_pay';
        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame([self::REGISTER], $this->requestKeys());
        self::assertSame(['domainName' => 'shop.example.com'], self::lastRequestBody());
    }

    public function testAMoneiRefusalStillSavesTheMethodAndShowsAWarning(): void
    {
        $this->setChannelHostname('shop.example.com');
        $method = $this->createMoneiPaymentMethod('MONEI_WALLETS', ['payment_method' => 'apple_google_pay']);
        MoneiMockHandler::appendFor(self::REGISTER, self::error(400, 'Domain is not reachable'));
        $this->loginAdmin();

        $form = $this->updateForm($method);
        $form[self::FORM.'[translations][en_US][name]'] = 'Apple Pay';
        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->client->followRedirect();
        self::assertStringContainsString(
            'MONEI did not register shop.example.com for Apple Pay (Domain is not reachable).',
            html_entity_decode((string) $this->client->getResponse()->getContent(), \ENT_QUOTES),
        );
        self::assertSame('Apple Pay', $this->secondConnection()->fetchOne(
            'SELECT name FROM sylius_payment_method_translation WHERE translatable_id = ?',
            [$method->getId()],
        ));
    }

    public function testSavingACardMethodSendsNothingToMonei(): void
    {
        $method = $this->createMoneiPaymentMethod('MONEI_CARD');
        $this->loginAdmin();

        $this->client->submit($this->updateForm($method));

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame([], $this->requestKeys());
    }

    public function testSavingAnOfflineMethodSendsNothingToMonei(): void
    {
        $method = $this->createOfflinePaymentMethod('BANK_TRANSFER');
        $this->loginAdmin();

        $this->client->submit($this->updateForm($method));

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame([], $this->requestKeys());
    }

    public function testAChannelWithoutHostnameRegistersTheRequestHost(): void
    {
        $this->setChannelHostname(null);
        $method = $this->createMoneiPaymentMethod('MONEI_WALLETS', ['payment_method' => 'apple_google_pay']);
        MoneiMockHandler::appendFor(self::REGISTER, self::registered());
        $this->client->setServerParameter('HTTP_HOST', 'store.example.org');
        $this->loginAdmin();

        $this->client->submit($this->updateForm($method));

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame(['domainName' => 'store.example.org'], self::lastRequestBody());
    }

    private function updateForm(PaymentMethodInterface $method): Form
    {
        $crawler = $this->client->request('GET', \sprintf('/admin/payment-methods/%d/edit', $method->getId()));

        return $crawler->filterXPath('//form[@name="'.self::FORM.'"]')->form();
    }

    private function setChannelHostname(?string $hostname): void
    {
        $this->secondConnection()->executeStatement('UPDATE sylius_channel SET hostname = ? WHERE code = ?', [$hostname, self::CHANNEL_CODE]);
    }

    private static function registered(): MoneiResponse
    {
        return new MoneiResponse(200, ['Content-Type' => 'application/json'], '{"success":true}');
    }
}
