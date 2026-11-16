<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Api;

use Monei\ApiException;
use Monei\SyliusPlugin\Api\MoneiApi;
use PHPUnit\Framework\TestCase;

final class MoneiApiTest extends TestCase
{
    /** The SDK gives code 0 for a connect error or a timeout: "HTTP 0" tells the admin nothing. */
    public function testReasonOfAConnectErrorIsNetworkError(): void
    {
        self::assertSame('network error', MoneiApi::reason(new ApiException('[0] cURL error 28', 0)));
    }
}
