<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Twig;

use Monei\Internal\GuzzleHttp\Client;
use Monei\Internal\GuzzleHttp\Exception\ConnectException;
use Monei\Internal\GuzzleHttp\Handler\MockHandler;
use Monei\Internal\GuzzleHttp\HandlerStack;
use Monei\Internal\GuzzleHttp\Middleware;
use Monei\Internal\GuzzleHttp\Psr7\Request;
use Monei\Internal\GuzzleHttp\Psr7\Response;
use Monei\Internal\Psr\Http\Message\RequestInterface;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\Twig\CardBrandLogos;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CardBrandLogosTest extends TestCase
{
    private const API_KEY = 'pk_test_0123456789abcdef';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];
    private MockHandler $moneiApi;
    private ArrayAdapter $cache;
    private CardBrandLogos $logos;

    protected function setUp(): void
    {
        $this->moneiApi = new MockHandler();
        $stack = HandlerStack::create($this->moneiApi);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);
        $this->cache = new ArrayAdapter();
        $this->logos = new CardBrandLogos(new MoneiApiFactory($client, 'test', $client), $this->cache, new NullLogger());
    }

    /** The test account accepts no Amex: a fixed list showed its logo anyway. */
    public function testShowsTheBrandsOfTheAccountAndSkipsBrandsWithoutALogo(): void
    {
        $this->moneiApi->append(self::brands(['visa', 'MasterCard', 'maestro', 'unionpay']));

        self::assertSame(['visa', 'master_card', 'maestro'], $this->logos->forApiKey(self::API_KEY));
        self::assertSame('/v1/allowed-payment-methods', $this->history[0]['request']->getUri()->getPath());
        self::assertSame(self::API_KEY, $this->history[0]['request']->getHeaderLine('Authorization'));
        // The cache may live in a shared store: its key must not hold the API key.
        self::assertStringNotContainsString(self::API_KEY, implode(' ', array_keys($this->cache->getValues())));
    }

    /** A slow or failing MONEI must not stall each render of the Payment step live component. */
    public function testAFailedCallShowsVisaAndMastercardAndIsNotRepeatedAtOnce(): void
    {
        $this->moneiApi->append(new ConnectException('timeout', new Request('GET', '/v1/allowed-payment-methods')));

        self::assertSame(['visa', 'master_card'], $this->logos->forApiKey(self::API_KEY));
        self::assertSame(['visa', 'master_card'], $this->logos->forApiKey(self::API_KEY));
        self::assertCount(1, $this->history);
    }

    public function testAnAccountWithoutAKnownBrandShowsVisaAndMastercard(): void
    {
        $this->moneiApi->append(self::brands(['unionpay']));

        self::assertSame(['visa', 'master_card'], $this->logos->forApiKey(self::API_KEY));
    }

    /** No e2e account shows every brand: a missing file would be a broken image only some stores see. */
    public function testEachBrandLogoShips(): void
    {
        foreach (CardBrandLogos::LOGOS as $logo) {
            self::assertFileExists(\dirname(__DIR__, 3).'/public/images/'.$logo.'.svg');
        }
    }

    /** @param list<string> $brands */
    private static function brands(array $brands): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'paymentMethods' => ['card'],
            'metadata' => ['card' => ['brands' => $brands]],
        ], \JSON_THROW_ON_ERROR));
    }
}
