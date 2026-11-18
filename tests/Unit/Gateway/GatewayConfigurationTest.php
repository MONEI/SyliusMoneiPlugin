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

    public function testUnknownModeIsRefusedOnMode(): void
    {
        self::assertRefusedOn('mode', ['mode' => 'embedded']);
    }

    /** MONEI allows 40 characters in an order ID; the order number needs the rest. */
    public function testPrefixOf21CharactersIsRefusedOnOrderIdPrefix(): void
    {
        self::assertRefusedOn('order_id_prefix', ['order_id_prefix' => str_repeat('a', 21)]);
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

    /** Methods saved before the option existed keep working and get the default layout. */
    public function testConfigWithoutCardLayoutUsesSplit(): void
    {
        self::assertSame('split', GatewayConfiguration::fromArray(self::VALID)->cardLayout);
    }

    /** A refused config makes bound payments unreachable; a cosmetic key must not block capture or refund. */
    public function testUnknownCardLayoutFallsBackToSplit(): void
    {
        self::assertSame('split', GatewayConfiguration::fromArray(['card_layout' => 'grid'] + self::VALID)->cardLayout);
    }

    /** A pasted API key must never reach the checkout page as an account ID; a refusal would block capture and refund. */
    public function testOnsiteIsInlineOnlyWithAUuidAccountId(): void
    {
        $onsite = ['mode' => 'onsite'] + self::VALID;

        self::assertFalse(GatewayConfiguration::fromArray(['account_id' => 'pk_test_0123456789abcdef'] + $onsite)->inline());
        self::assertTrue(GatewayConfiguration::fromArray(['account_id' => '00000000-0000-4000-8000-000000000000'] + $onsite)->inline());
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
