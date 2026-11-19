<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Api;

use Monei\Internal\GuzzleHttp\Client;
use Monei\Internal\GuzzleHttp\Handler\MockHandler;
use Monei\Internal\GuzzleHttp\HandlerStack;
use Monei\Internal\GuzzleHttp\Middleware;
use Monei\Internal\GuzzleHttp\Psr7\Response;
use Monei\Internal\Psr\Http\Message\RequestInterface;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use PHPUnit\Framework\TestCase;

final class MoneiApiFactoryTest extends TestCase
{
    /** The SDK's default Configuration is a process-wide singleton: a second key must not replace the first. */
    public function testEachApiKeepsItsOwnKeyAndSendsThePluginUserAgent(): void
    {
        /** @var list<array{request: RequestInterface}> $history */
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"pay_a"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"pay_b"}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        $factory = new MoneiApiFactory($client, 'MONEI/Sylius/2.0.1 (Sylius v2.3.2; PHP v8.3.36)', $client);

        $apiA = $factory->forApiKey('pk_test_aaaa');
        $apiB = $factory->forApiKey('pk_test_bbbb');
        $apiA->getPayment('pay_a');
        $apiB->getPayment('pay_b');

        self::assertSame('pk_test_aaaa', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('pk_test_bbbb', $history[1]['request']->getHeaderLine('Authorization'));
        self::assertSame('MONEI/Sylius/2.0.1 (Sylius v2.3.2; PHP v8.3.36)', $history[0]['request']->getHeaderLine('User-Agent'));
        self::assertSame('MONEI/Sylius/2.0.1 (Sylius v2.3.2; PHP v8.3.36)', $history[1]['request']->getHeaderLine('User-Agent'));
    }
}
