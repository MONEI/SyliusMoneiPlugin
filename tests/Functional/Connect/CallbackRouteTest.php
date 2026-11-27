<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Connect;

use Monei\Internal\GuzzleHttp\Psr7\Response;
use Monei\SyliusPlugin\Connect\ConnectionStore;
use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CallbackRouteTest extends MoneiTestCase
{
    private const STATE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const VERIFIER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const REDIRECT_URI = 'https://shop.example/installation/monei/connect';

    #[DataProvider('invalidCallbacks')]
    public function testInvalidCallbackCannotExchangeOrChangeTheLastResult(string $query, string $message): void
    {
        $this->seedState();
        $previous = ['switched' => ['live'], 'problems' => []];
        $this->store()->writeResult($previous);

        $page = $this->client->request('GET', '/monei/connect?'.$query);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertNull($this->client->getResponse()->headers->get('Location'));
        self::assertStringContainsString($message, $page->filterXPath('//main')->text());
        self::assertNotNull($this->store()->readState(self::STATE));
        self::assertEquals($previous, $this->store()->read()->lastResult);
        self::assertSame([], MoneiMockHandler::history());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidCallbacks(): iterable
    {
        yield 'forged state' => ['code=fixture&state='.str_repeat('A', 43), 'The connection request is invalid or was already used.'];
        yield 'missing code' => ['state='.self::STATE, 'The authorization code is missing.'];
        yield 'array code' => ['state='.self::STATE.'&code[]=fixture', 'The authorization code is missing.'];
    }

    public function testPublicCallbackExchangesTheStoredPkceValuesAndProbesTheNewKeyWithoutLeakingCredentials(): void
    {
        $key = 'mk_test_replacement_fixture';
        $secret = 'mksec_replacement_fixture';
        $this->channel->setHostname('shop.example');
        $this->createConnectedTestMethod(['payment_method' => 'apple_google_pay']);
        $this->createMoneiPaymentMethod('MONEI_MANUAL');
        $this->entityManager()->flush();
        $configBefore = $this->secondConnection()->fetchFirstColumn('SELECT config FROM sylius_gateway_config ORDER BY id');
        $this->seedState();

        MoneiMockHandler::appendFor('POST /integrations/token', new Response(200, [], json_encode([
            'test' => ['accountId' => self::ACCOUNT_ID, 'apiKey' => $key, 'signingSecret' => $secret],
            'errors' => ['live' => $secret],
        ], \JSON_THROW_ON_ERROR)));
        MoneiMockHandler::appendFor('GET /allowed-payment-methods', new Response(204));
        MoneiMockHandler::appendFor('POST /apple-pay/domains', new Response(400, ['Content-Type' => 'application/json'], json_encode([
            'message' => $key.' '.$secret,
        ], \JSON_THROW_ON_ERROR)));

        $page = $this->client->request('GET', 'https://callback.example/monei/connect?code=fixture&state='.self::STATE);

        self::assertResponseIsSuccessful();
        self::assertSame('MONEI connected. You can close this tab.', $page->filterXPath('//h1')->text());
        self::assertStringContainsString('MONEI could not register one or more Apple Pay domains.', $page->filterXPath('//main')->text());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertTrue(!str_contains($html, 'mk_') && !str_contains($html, 'mksec_'));
        [$exchange, $probe] = MoneiMockHandler::history();
        parse_str((string) $exchange->getBody(), $form);
        self::assertSame(['code' => 'fixture', 'code_verifier' => self::VERIFIER, 'client_id' => 'sylius', 'redirect_uri' => self::REDIRECT_URI], $form);
        self::assertTrue($key === $probe->getHeaderLine('Authorization'));

        $snapshot = $this->store()->read();
        self::assertSame(self::ACCOUNT_ID, $snapshot->testAccountId);
        self::assertTrue($key === $snapshot->testApiKey && $secret === $snapshot->testSigningSecret);
        self::assertNull($this->store()->readState(self::STATE));
        self::assertTrue($configBefore === $this->secondConnection()->fetchFirstColumn('SELECT config FROM sylius_gateway_config ORDER BY id'), 'Connect must not change the stored credentials of any payment method.');

        $raw = $this->entityManager()->getConnection()->fetchOne('SELECT last_result FROM monei_connection');
        self::assertIsString($raw);
        self::assertTrue(!str_contains($raw, 'mk_') && !str_contains($raw, 'mksec_'));
        self::assertNotEmpty($this->moneiLogs());
        foreach ($this->moneiLogs() as $record) {
            self::assertTrue(!str_contains($record['text'], 'mk_') && !str_contains($record['text'], 'mksec_'));
        }
    }

    public function testBrokerResponseMissingASigningSecretCannotReplaceTheWorkingKey(): void
    {
        $this->createConnectedTestMethod();
        $this->seedState();
        MoneiMockHandler::appendFor('POST /integrations/token', new Response(200, [], json_encode([
            'live' => ['accountId' => self::ACCOUNT_ID, 'apiKey' => 'mk_live_replacement_fixture'],
            'test' => ['accountId' => self::ACCOUNT_ID, 'apiKey' => 'mk_test_replacement_fixture', 'signingSecret' => 'mksec_replacement_fixture'],
        ], \JSON_THROW_ON_ERROR)));

        $page = $this->client->request('GET', '/monei/connect?code=fixture&state='.self::STATE);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('MONEI Connect is temporarily unavailable. Try again later.', $page->filterXPath('//main')->text());
        self::assertEquals(['switched' => [], 'problems' => [['mode' => null, 'code' => 'temporarily_unavailable']]], $this->store()->read()->lastResult);
        self::assertSame(['POST /integrations/token'], $this->requestKeys());
        self::assertTrue('mk_test_fixture' === $this->store()->read()->testApiKey);
        self::assertTrue('mksec_test_fixture' === $this->store()->read()->testSigningSecret);
        self::assertNull($this->store()->read()->liveApiKey);
        self::assertNull($this->store()->readState(self::STATE));
    }

    private function seedState(): void
    {
        $this->store()->insertState(self::STATE, self::VERIFIER, self::REDIRECT_URI, 0, new \DateTimeImmutable());
    }

    private function store(): ConnectionStore
    {
        $store = self::getContainer()->get('monei.test.connection_store');
        \assert($store instanceof ConnectionStore);

        return $store;
    }
}
