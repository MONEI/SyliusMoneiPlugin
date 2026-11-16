<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Gateway;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\InvalidGatewayConfigurationException;
use PHPUnit\Framework\TestCase;

final class GatewayConfigurationTest extends TestCase
{
    private const VALID = [
        'api_key' => 'pk_test_0123456789abcdef',
        'payment_method' => 'card',
        'mode' => 'hosted',
        'use_authorize' => false,
    ];

    public function testOnsiteMbWayIsRefusedOnMode(): void
    {
        self::assertRefusedOn('mode', ['payment_method' => 'mbway', 'mode' => 'onsite']);
    }

    public function testAuthorizeWithBizumIsRefusedOnUseAuthorize(): void
    {
        self::assertRefusedOn('use_authorize', ['payment_method' => 'bizum', 'use_authorize' => true]);
    }

    public function testSecretKeyIsRefusedOnApiKey(): void
    {
        self::assertRefusedOn('api_key', ['api_key' => 'sk_live_0123456789abcdef']);
    }

    /** Sylius's DefaultActionProvider creates an `authorize` request for any truthy `use_authorize`. */
    public function testStringOneAuthorizes(): void
    {
        self::assertTrue(GatewayConfiguration::fromArray(['use_authorize' => '1'] + self::VALID)->authorize);
    }

    /** @param array<string, mixed> $config */
    private static function assertRefusedOn(string $field, array $config): void
    {
        try {
            GatewayConfiguration::fromArray($config + self::VALID);
        } catch (InvalidGatewayConfigurationException $exception) {
            self::assertSame($field, $exception->field);

            return;
        }

        self::fail('The config was accepted.');
    }
}
