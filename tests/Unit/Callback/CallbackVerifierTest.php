<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Callback;

use Monei\SyliusPlugin\Callback\CallbackVerifier;
use Monei\SyliusPlugin\Callback\InvalidCallbackException;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\MoneiMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class CallbackVerifierTest extends TestCase
{
    private const ID = 'aabbccddeeff00112233445566778899';
    private const BODY = '{"id":"'.self::ID.'"}';
    private const SECRET = 'mksec_fixture';
    private const KEY = 'mk_test_fixture';

    public function testMatchingV2IsRecognizedAndBypassesTheUnsignedPath(): void
    {
        $header = self::signature('v2', 'mksec_previous').','.self::signaturePart('v2', self::SECRET);

        $callback = CallbackVerifier::verify(self::BODY, $header, self::configuration());

        self::assertTrue($callback->signed);
        self::assertSame(self::ID, $callback->paymentId);
    }

    #[DataProvider('unsignedHeaders')]
    public function testUnprovenConnectedSignaturesTakeTheUnsignedPath(?string $header): void
    {
        $callback = CallbackVerifier::verify(self::BODY, $header, self::configuration());

        self::assertFalse($callback->signed);
        self::assertSame(self::ID, $callback->paymentId);
    }

    /** @return iterable<string, array{?string}> */
    public static function unsignedHeaders(): iterable
    {
        yield 'v1 from the scoped key' => [self::signature('v1', self::KEY)];
        yield 'v2 from a rotated secret' => [self::signature('v2', 'mksec_previous')];
        yield 'header absent' => [null];
    }

    public function testAnEmptySecretCannotAuthenticateAnEmptyKeyHmac(): void
    {
        // Model a corrupted credential snapshot, bypassing gateway validation.
        $class = new \ReflectionClass(GatewayConfiguration::class);
        $configuration = $class->newInstanceWithoutConstructor();
        $class->getConstructor()?->invoke(
            $configuration, self::KEY, MoneiMethod::Card, false, false, '', 'split',
            '00000000-0000-4000-8000-000000000000', 'connected_test', '',
        );

        self::assertFalse(CallbackVerifier::verify(self::BODY, self::signature('v2', ''), $configuration)->signed);
    }

    #[DataProvider('malformedCallbacks')]
    public function testMalformedUnsignedCallbacksAreRefusedBeforeTheyCanBePersisted(string $body, ?string $header): void
    {
        $this->expectException(InvalidCallbackException::class);
        try {
            CallbackVerifier::verify($body, $header, self::configuration());
        } catch (InvalidCallbackException $e) {
            self::assertSame(Response::HTTP_BAD_REQUEST, $e->httpStatus);

            throw $e;
        }
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function malformedCallbacks(): iterable
    {
        yield 'malformed signature' => [self::BODY, 't=1763300000,v2=broken'];
        yield 'missing timestamp' => [self::BODY, self::signaturePart('v1', self::KEY)];
        yield 'malformed payment id' => ['{"id":"not-a-monei-id"}', null];
        yield 'body over 16 KB' => [str_pad(self::BODY, 16385), null];
    }

    private static function configuration(): GatewayConfiguration
    {
        return GatewayConfiguration::fromArray([
            'api_key' => self::KEY,
            'signing_secret' => self::SECRET,
            'account_id' => '00000000-0000-4000-8000-000000000000',
            'credentials' => 'connected_test',
            'payment_method' => 'card',
        ]);
    }

    private static function signature(string $version, string $secret): string
    {
        return 't=1763300000,'.self::signaturePart($version, $secret);
    }

    private static function signaturePart(string $version, string $secret): string
    {
        return $version.'='.hash_hmac('sha256', '1763300000.'.self::BODY, $secret);
    }
}
