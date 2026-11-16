<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Admin;

use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;

final class GatewayFormTest extends MoneiTestCase
{
    private const FORM = 'sylius_admin_payment_method';
    private const CONFIG = self::FORM.'[gatewayConfig][config]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->loginUser($this->admin, 'admin');
    }

    public function testCreateSavesTheFiveValues(): void
    {
        $form = $this->createForm('MONEI_PAYPAL');
        $form[self::CONFIG.'[api_key]'] = 'pk_live_newstorekey';
        $form[self::CONFIG.'[payment_method]'] = 'paypal';
        $form[self::CONFIG.'[mode]'] = 'onsite';
        $form[self::CONFIG.'[use_authorize]']->tick();
        $form[self::CONFIG.'[order_id_prefix]'] = 'shop-1';

        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertConfig([
            'api_key' => 'pk_live_newstorekey',
            'mode' => 'onsite',
            'order_id_prefix' => 'shop-1',
            'payment_method' => 'paypal',
            'use_authorize' => true,
        ], 'MONEI_PAYPAL');
    }

    public function testBizumWithAuthorizeShowsTheErrorOnUseAuthorizeAndSavesNothing(): void
    {
        $form = $this->createForm('MONEI_BIZUM');
        $form[self::CONFIG.'[api_key]'] = self::API_KEY;
        $form[self::CONFIG.'[payment_method]'] = 'bizum';
        $form[self::CONFIG.'[use_authorize]']->tick();

        $crawler = $this->client->submit($form);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'This payment method cannot authorize. It always charges at once.',
            $this->fieldRow($crawler, 'use_authorize')->text(),
        );
        self::assertSame(0, (int) $this->secondConnection()->fetchOne('SELECT COUNT(*) FROM sylius_payment_method'));
    }

    public function testUpdateShowsTheSavedValuesAndSavesANewApiKey(): void
    {
        $method = $this->createMoneiPaymentMethod('MONEI_BIZUM', ['payment_method' => 'bizum', 'mode' => 'onsite']);

        $crawler = $this->client->request('GET', \sprintf('/admin/payment-methods/%d/edit', $method->getId()));
        $form = $crawler->filterXPath('//form[@name="'.self::FORM.'"]')->form();
        self::assertSame('bizum', $form[self::CONFIG.'[payment_method]']->getValue());
        self::assertSame('onsite', $form[self::CONFIG.'[mode]']->getValue());

        $form[self::CONFIG.'[api_key]'] = 'pk_test_rotatedkey';
        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('pk_test_rotatedkey', $this->savedConfig('MONEI_BIZUM')['api_key'] ?? null);
    }

    public function testGatewayConfigPersistedWithUsePayumIsStoredWithout(): void
    {
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        \assert($gatewayConfig instanceof GatewayConfigInterface);
        $gatewayConfig->setFactoryName('monei');
        $gatewayConfig->setGatewayName('monei_fixture');
        $gatewayConfig->setConfig(['api_key' => self::API_KEY, 'payment_method' => 'card']);
        $gatewayConfig->setUsePayum(true);

        $this->entityManager()->persist($gatewayConfig);
        $this->entityManager()->flush();

        self::assertSame(0, (int) $this->secondConnection()->fetchOne(
            'SELECT use_payum FROM sylius_gateway_config WHERE gateway_name = ?',
            ['monei_fixture'],
        ));
    }

    private function createForm(string $code): Form
    {
        $crawler = $this->client->request('GET', '/admin/payment-methods/new/monei');
        $form = $crawler->filterXPath('//form[@name="'.self::FORM.'"]')->form();
        $form[self::FORM.'[code]'] = $code;
        $form[self::FORM.'[translations][en_US][name]'] = $code;

        return $form;
    }

    private function fieldRow(Crawler $crawler, string $field): Crawler
    {
        return $crawler->filterXPath(\sprintf(
            '//*[@id="%s_gatewayConfig_config_%s"]/ancestor::div[contains(@class, "field")][1]',
            self::FORM,
            $field,
        ));
    }

    /** @param array<string, mixed> $expected */
    private function assertConfig(array $expected, string $code): void
    {
        $config = $this->savedConfig($code);
        ksort($config);
        self::assertSame($expected, $config);
    }

    /** @return array<string, mixed> the gateway config as decrypted from a fresh load */
    private function savedConfig(string $code): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $method = $entityManager->getRepository(PaymentMethodInterface::class)->findOneBy(['code' => $code]);
        \assert($method instanceof PaymentMethodInterface);

        return $method->getGatewayConfig()?->getConfig() ?? [];
    }
}
