<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Client;

use Monei\SyliusPlugin\Client\MoneiApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Note: Full tests for MoneiApiClient require mocking the MoneiClient SDK.
 * These tests verify the wrapper's constructor and accessor methods.
 * Integration tests against the MONEI sandbox are recommended for end-to-end validation.
 */
final class MoneiApiClientTest extends TestCase
{
    public function testItExposesApiKey(): void
    {
        $client = new MoneiApiClient('pk_test_abc123', 'acc_test_456');

        $this->assertSame('pk_test_abc123', $client->getApiKey());
    }

    public function testItExposesAccountId(): void
    {
        $client = new MoneiApiClient('pk_test_abc123', 'acc_test_456');

        $this->assertSame('acc_test_456', $client->getAccountId());
    }
}
