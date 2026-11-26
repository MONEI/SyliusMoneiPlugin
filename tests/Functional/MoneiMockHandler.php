<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional;

use Monei\Internal\GuzzleHttp\Promise\Create;
use Monei\Internal\GuzzleHttp\Promise\PromiseInterface;
use Monei\Internal\GuzzleHttp\Psr7\Response;
use Monei\Internal\Psr\Http\Message\RequestInterface;
use Monei\Internal\Psr\Http\Message\ResponseInterface;

/**
 * Guzzle handler that stands in for the MONEI API in the test env.
 *
 * State is static: KernelBrowser reboots the kernel between requests, which
 * builds a new handler instance each time.
 */
final class MoneiMockHandler
{
    private const API_PATH_PREFIX = '/v1';

    /**
     * Each page with a MONEI card method asks for the account's card brands. Without a queued
     * answer, this call gets Visa and Mastercard and stays out of the history.
     */
    private const CARD_BRANDS = 'GET /allowed-payment-methods';

    /** @var list<ResponseInterface|\Throwable|\Closure> */
    private static array $queue = [];

    /** @var array<string, list<ResponseInterface|\Throwable|\Closure>> keyed by "<METHOD> <path>" */
    private static array $queueByPath = [];

    /** @var list<RequestInterface> */
    private static array $history = [];

    /** @var list<string> "<METHOD> <path>" of each request that found no response */
    private static array $unanswered = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$queueByPath = [];
        self::$history = [];
        self::$unanswered = [];
    }

    /** Answers the next request that no path-keyed response matches. */
    public static function append(ResponseInterface|\Throwable|\Closure ...$responses): void
    {
        foreach ($responses as $response) {
            self::$queue[] = $response;
        }
    }

    /** Answers the next request to `$key`, for example "GET /payments/pay_1" (without the "/v1" prefix). */
    public static function appendFor(string $key, ResponseInterface|\Throwable|\Closure ...$responses): void
    {
        foreach ($responses as $response) {
            self::$queueByPath[$key][] = $response;
        }
    }

    /** @return list<RequestInterface> */
    public static function history(): array
    {
        return self::$history;
    }

    /** @return list<string> */
    public static function unanswered(): array
    {
        return self::$unanswered;
    }

    /** @return list<string> */
    public static function unused(): array
    {
        $unused = array_map(static fn (ResponseInterface|\Throwable|\Closure $response): string => 'FIFO '.self::describe($response), self::$queue);
        foreach (self::$queueByPath as $key => $responses) {
            foreach ($responses as $response) {
                $unused[] = $key.' '.self::describe($response);
            }
        }

        return $unused;
    }

    public static function key(RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, self::API_PATH_PREFIX.'/')) {
            $path = substr($path, \strlen(self::API_PATH_PREFIX));
        }

        return $request->getMethod().' '.$path;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $key = self::key($request);
        if (self::CARD_BRANDS === $key && [] === (self::$queueByPath[$key] ?? [])) {
            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"paymentMethods":["card"],"metadata":{"card":{"brands":["visa","mastercard"]}}}'));
        }
        self::$history[] = $request;

        if ([] !== (self::$queueByPath[$key] ?? [])) {
            $response = array_shift(self::$queueByPath[$key]);
            if ([] === self::$queueByPath[$key]) {
                unset(self::$queueByPath[$key]);
            }
        } elseif ([] !== self::$queue) {
            $response = array_shift(self::$queue);
        } else {
            self::$unanswered[] = $key;

            return Create::rejectionFor(new \OutOfBoundsException(\sprintf('No mocked MONEI response for "%s".', $key)));
        }

        if ($response instanceof \Closure) {
            $response = $response();
        }

        return $response instanceof \Throwable ? Create::rejectionFor($response) : Create::promiseFor($response);
    }

    private static function describe(ResponseInterface|\Throwable|\Closure $response): string
    {
        return $response instanceof ResponseInterface ? (string) $response->getStatusCode() : $response::class;
    }
}
