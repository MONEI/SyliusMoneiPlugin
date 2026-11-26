<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Connect;

use Doctrine\DBAL\Exception\DriverException;
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

    public function testDisconnectDuringApplePayRegistrationKeepsTheLastResultCleared(): void
    {
        $this->channel->setHostname('shop.example');
        $this->createMoneiPaymentMethod('WALLET', ['payment_method' => 'apple_google_pay', 'credentials' => 'connected_test', 'api_key' => '']);
        $this->entityManager()->flush();
        $service = self::getContainer()->get('monei.test.connect_service');
        \assert($service instanceof ConnectService);
        $url = $service->begin('https://shop.example/monei/connect');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        MoneiMockHandler::appendFor('POST /integrations/token', new Response(200, [], json_encode([
            'test' => ['accountId' => self::ACCOUNT_ID, 'apiKey' => 'mk_test_new', 'signingSecret' => 'mksec_new'],
        ], \JSON_THROW_ON_ERROR)));
        MoneiMockHandler::appendFor('GET /allowed-payment-methods', new Response(204));
        MoneiMockHandler::appendFor('POST /apple-pay/domains', static function () use ($service): Response {
            $service->disconnect();

            return new Response(400);
        });

        $result = $service->complete($query['state'], 'code');

        self::assertSame([['mode' => 'test', 'code' => 'applePayRegistrationFailed']], $result['problems']);
        $row = $this->secondConnection()->fetchAssociative('SELECT generation, test_account_id, last_result FROM monei_connection WHERE id = 1');
        self::assertEquals(['generation' => 1, 'test_account_id' => null, 'last_result' => null], $row);
    }

    public function testFailureAfterTheLiveUpdateRollsBackBothCredentialSetsAndThePreviousResult(): void
    {
        $service = self::getContainer()->get('monei.test.connect_service');
        \assert($service instanceof ConnectService);
        $store = self::getContainer()->get('monei.test.connection_store');
        \assert($store instanceof ConnectionStore);
        $store->writeMode(true, self::ACCOUNT_ID, 'mk_live_old', 'mksec_live_old');
        $store->writeMode(false, self::ACCOUNT_ID, 'mk_test_old', 'mksec_test_old');
        $store->writeResult(['previous' => 'result']);
        $url = $service->begin('https://shop.example/monei/connect');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        MoneiMockHandler::appendFor('POST /integrations/token', new Response(200, [], json_encode([
            'live' => ['accountId' => self::ACCOUNT_ID, 'apiKey' => 'mk_live_new', 'signingSecret' => 'mksec_live_new'],
            'test' => ['accountId' => self::ACCOUNT_ID, 'apiKey' => 'mk_test_new', 'signingSecret' => 'mksec_test_new'],
        ], \JSON_THROW_ON_ERROR)));
        MoneiMockHandler::appendFor('GET /allowed-payment-methods', new Response(204), new Response(204));

        $observer = $this->secondConnection();
        $before = $observer->fetchAssociative('SELECT * FROM monei_connection WHERE id = 1');
        $liveDigest = (string) $observer->fetchOne('SELECT SHA2(live_api_key, 256) FROM monei_connection WHERE id = 1');
        $observer->executeStatement(<<<SQL
            CREATE TRIGGER monei_fail_test_update BEFORE UPDATE ON monei_connection FOR EACH ROW
            BEGIN
                IF NEW.test_api_key <> OLD.test_api_key THEN
                    IF SHA2(OLD.live_api_key, 256) = '$liveDigest' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Live update did not run first';
                    END IF;
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test update refused after Live update';
                END IF;
            END
            SQL);

        try {
            $service->complete($query['state'], 'code');
            self::fail('The Test update should fail after the Live update.');
        } catch (DriverException $e) {
            self::assertStringContainsString('Test update refused after Live update', $e->getMessage());
        } finally {
            $observer->executeStatement('DROP TRIGGER monei_fail_test_update');
        }

        self::assertTrue($before === $observer->fetchAssociative('SELECT * FROM monei_connection WHERE id = 1'), 'The committed credentials and previous result must survive a failed second update.');
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
