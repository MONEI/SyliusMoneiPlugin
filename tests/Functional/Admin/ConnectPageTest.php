<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Admin;

use Monei\SyliusPlugin\Connect\ConnectionStore;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Component\Core\Model\ChannelInterface;

final class ConnectPageTest extends MoneiTestCase
{
    private const PAGE = '/admin/monei';
    private const START = self::PAGE.'/connect/start';
    private const DISCONNECT = self::PAGE.'/connect/disconnect';

    public function testForgedStartAndGetWithValidTokenCannotCreatePendingState(): void
    {
        $this->addChannel('FIRST', 'shop.example');
        $this->loginAdmin();
        $token = $this->csrfToken('monei_connect_start');

        $this->client->request('POST', self::START);
        self::assertResponseStatusCodeSame(403);
        $this->assertNoPendingState();
        self::assertFalse($this->entityManager()->getConnection()->fetchOne('SELECT id FROM monei_connection'));

        $this->client->request('GET', self::START.'?_token='.rawurlencode($token));
        self::assertResponseStatusCodeSame(405);
        $this->assertNoPendingState();
    }

    public function testForgedDisconnectAndGetWithValidTokenCannotClearTheConnection(): void
    {
        $this->createConnectedTestMethod();
        $this->createMoneiPaymentMethod('MONEI_MANUAL');
        $db = $this->secondConnection();
        $configBefore = $db->fetchFirstColumn('SELECT config FROM sylius_gateway_config ORDER BY id');
        $this->loginAdmin();
        $token = $this->csrfToken('monei_connect_disconnect');

        $this->client->request('POST', self::DISCONNECT);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(self::ACCOUNT_ID, $this->store()->read()->testAccountId);

        $this->client->request('GET', self::DISCONNECT.'?_token='.rawurlencode($token));
        self::assertResponseStatusCodeSame(405);
        self::assertSame(self::ACCOUNT_ID, $this->store()->read()->testAccountId);

        $this->client->request('POST', self::DISCONNECT, ['_token' => $token]);
        self::assertResponseRedirects(self::PAGE, 303);
        self::assertNull($this->store()->read()->testAccountId);
        self::assertTrue($configBefore === $db->fetchFirstColumn('SELECT config FROM sylius_gateway_config ORDER BY id'), 'Disconnect must preserve the stored payment method configuration.');
    }

    #[DataProvider('adminRoutes')]
    public function testAnonymousRequestsAreSentToAdminLogin(string $method, string $route): void
    {
        $this->client->request($method, $route);

        self::assertResponseRedirects('/admin/login');
    }

    /** @return iterable<array{string, string}> */
    public static function adminRoutes(): iterable
    {
        yield ['GET', self::PAGE];
        yield ['POST', self::START];
        yield ['POST', self::DISCONNECT];
    }

    public function testStartFromHttpUsesFirstEligibleEnabledChannelAndBindsTheStoredVerifier(): void
    {
        $this->addChannel('ZZ_LATER', 'later.example');
        $this->addChannel('B_DISABLED', 'disabled.example', false);
        $this->addChannel('A_REJECTED', '127.0.0.1');
        $this->addChannel('C_ACCEPTED', 'Shop.Example');
        $this->loginAdmin();
        $page = $this->client->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        $callback = 'https://shop.example/monei/connect';
        self::assertSame($callback, $page->filterXPath('//main//code')->text());
        self::assertCount(1, $page->filterXPath('//aside//a[@href="'.self::PAGE.'"]'));
        self::assertCount(1, $page->filterXPath('//form[@action="'.self::START.'" and @target="_blank"]'));
        self::assertFalse($this->entityManager()->getConnection()->fetchOne('SELECT id FROM monei_connection'));

        $this->client->submit($page->selectButton('Connect with MONEI')->form());
        self::assertResponseStatusCodeSame(303);
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('sylius', $query['client_id']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame($callback, $query['redirect_uri']);
        self::assertIsString($query['state']);
        $state = $this->store()->readState($query['state']);
        self::assertNotNull($state);
        self::assertSame($callback, $state->redirectUri);
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $state->verifier, true)), '+/', '-_'), '='), $query['code_challenge']);
    }

    public function testLocalhostOnHttpOffersManualKeysAndRefusesDirectStart(): void
    {
        $this->loginAdmin();
        $page = $this->client->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Use manual keys', $page->filterXPath('//main')->text());
        self::assertCount(0, $page->filterXPath('//form[@action="'.self::START.'"]'));

        $this->client->request('POST', self::START, ['_token' => $this->csrfToken('monei_connect_start')]);
        self::assertResponseStatusCodeSame(400);
        $this->assertNoPendingState();
    }

    public function testRejectedChannelFallsBackToTheHttpsRequestWithItsInstallPrefix(): void
    {
        $this->loginAdmin();
        $page = $this->client->request('GET', 'https://admin.example/shop'.self::PAGE, server: [
            'SCRIPT_NAME' => '/shop/index.php',
            'SCRIPT_FILENAME' => '/app/public/index.php',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('https://admin.example/shop/monei/connect', $page->filterXPath('//main//code')->text());
    }

    public function testPageMasksConnectedAndManualKeysAndShowsTheLastResult(): void
    {
        $this->createConnectedTestMethod();
        $this->createMoneiPaymentMethod('MONEI_MANUAL');
        $this->createOfflinePaymentMethod('OFFLINE');
        $this->store()->writeResult(['switched' => ['test'], 'problems' => [['mode' => 'live', 'code' => 'not_approved']]]);
        $this->loginAdmin();
        $page = $this->client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $content = $page->filterXPath('//main')->text();
        self::assertStringContainsString('•••• ture', $content);
        self::assertStringContainsString(self::ACCOUNT_ID, $content);
        self::assertStringContainsString('Test connected.', $content);
        self::assertStringContainsString('Live keys are available after MONEI approves your account.', $content);
        self::assertCount(1, $page->selectLink('MONEI_MANUAL'));
        self::assertCount(0, $page->selectLink('OFFLINE'));
        self::assertCount(0, $page->selectLink('MONEI_CONNECTED'));
        self::assertCount(1, $page->filterXPath('//main//a[@href="https://dashboard.monei.com/settings/api"]'));
        $html = $this->client->getResponse()->getContent();
        self::assertTrue(\is_string($html) && !str_contains($html, 'mk_test_fixture') && !str_contains($html, 'mksec_test_fixture') && !str_contains($html, self::API_KEY));
    }

    private function assertNoPendingState(): void
    {
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM monei_connect_state'));
    }

    private function store(): ConnectionStore
    {
        $store = self::getContainer()->get('monei.test.connection_store');
        \assert($store instanceof ConnectionStore);

        return $store;
    }

    private function addChannel(string $code, string $hostname, bool $enabled = true): void
    {
        $manager = $this->entityManager();
        /** @var ChannelInterface $channel */
        $channel = self::getContainer()->get('sylius.factory.channel')->createNew();
        $channel->setCode($code);
        $channel->setName($code);
        $channel->setHostname($hostname);
        $channel->setEnabled($enabled);
        $channel->setBaseCurrency($this->channel->getBaseCurrency());
        $channel->setDefaultLocale($this->channel->getDefaultLocale());
        $channel->setTaxCalculationStrategy('order_items_based');
        $manager->persist($channel);
        $manager->flush();
    }
}
