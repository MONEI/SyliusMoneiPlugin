<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Connect;

use Monei\Internal\GuzzleHttp\Psr7\Response;
use Monei\SyliusPlugin\Connect\ConnectionStore;
use Monei\SyliusPlugin\Connect\ConnectService;
use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;

final class ConnectServiceTest extends MoneiTestCase
{
    public function testApplePayRegistrationRefusalPreservesTheNewKeyAndSkipsOtherMethods(): void
    {
        $this->channel->setHostname('shop.example');
        $wallet = ['payment_method' => 'apple_google_pay', 'api_key' => ''];
        $this->createMoneiPaymentMethod('TEST_WALLET', $wallet + ['credentials' => 'connected_test']);
        $this->createMoneiPaymentMethod('LIVE_WALLET', $wallet + ['credentials' => 'connected_live']);
        $this->createMoneiPaymentMethod('TEST_CARD', ['credentials' => 'connected_test', 'api_key' => '']);
        $disabled = $this->createMoneiPaymentMethod('DISABLED_WALLET', $wallet + ['credentials' => 'connected_test']);
        $disabled->setEnabled(false);
        $this->entityManager()->flush();

        $service = self::getContainer()->get('monei.test.connect_service');
        \assert($service instanceof ConnectService);
        $store = self::getContainer()->get('monei.test.connection_store');
        \assert($store instanceof ConnectionStore);
        $url = $service->begin('https://shop.example/monei/connect');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        MoneiMockHandler::appendFor('POST /integrations/token', new Response(200, [], json_encode([
            'test' => ['accountId' => self::ACCOUNT_ID, 'apiKey' => 'mk_test_new', 'signingSecret' => 'mksec_new'],
        ], \JSON_THROW_ON_ERROR)));
        MoneiMockHandler::appendFor('GET /allowed-payment-methods', new Response(400));
        MoneiMockHandler::appendFor('POST /apple-pay/domains', function (): Response {
            $request = MoneiMockHandler::history()[2];
            self::assertTrue('mk_test_new' === $request->getHeaderLine('Authorization'));
            self::assertSame(['domainName' => 'shop.example'], json_decode((string) $request->getBody(), true, flags: \JSON_THROW_ON_ERROR));
            self::assertSame(self::ACCOUNT_ID, $this->secondConnection()->fetchOne('SELECT test_account_id FROM monei_connection WHERE id = 1'));

            return new Response(400, ['Content-Type' => 'application/json'], '{"message":"Domain is not reachable"}');
        });

        $result = $service->complete($query['state'], 'code');

        self::assertSame(['switched' => ['test'], 'problems' => [['mode' => 'test', 'code' => 'applePayRegistrationFailed']]], $result);
        self::assertEquals($result, $store->read()->lastResult);
        self::assertCount(3, MoneiMockHandler::history());
    }

    public function testSwapLockTimeoutLeavesBothCredentialsAndResultUntouched(): void
    {
        $service = self::getContainer()->get('monei.test.connect_service');
        \assert($service instanceof ConnectService);
        $store = self::getContainer()->get('monei.test.connection_store');
        \assert($store instanceof ConnectionStore);

        $store->writeResult(['previous' => 'result']);
        $url = $service->begin('https://shop.example/monei/connect');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        MoneiMockHandler::appendFor('POST /integrations/token', new Response(200, [], json_encode([
            'test' => ['accountId' => self::ACCOUNT_ID, 'apiKey' => 'mk_test_new', 'signingSecret' => 'mksec_new'],
        ], \JSON_THROW_ON_ERROR)));
        MoneiMockHandler::appendFor('GET /allowed-payment-methods', new Response(204));
        $this->holdRowLock('monei_connection', 1);

        $result = $service->complete($query['state'], 'code');

        self::assertSame(['switched' => [], 'problems' => [['mode' => null, 'code' => 'temporarily_unavailable']]], $result);
        self::assertNull($store->readState($query['state']));
        $snapshot = $store->read();
        self::assertNull($snapshot->testAccountId);
        self::assertNull($snapshot->testApiKey);
        self::assertNull($snapshot->testSigningSecret);
        self::assertSame(['previous' => 'result'], $snapshot->lastResult);
        self::assertCount(2, MoneiMockHandler::history());
    }
}
