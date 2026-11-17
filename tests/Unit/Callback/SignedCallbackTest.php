<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Callback;

use Monei\SyliusPlugin\Callback\InvalidCallbackException;
use Monei\SyliusPlugin\Callback\SignedCallback;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The `v1` values are HMAC-SHA256 of "<t>.<body>" with KEY, computed with Python's hmac module. */
final class SignedCallbackTest extends TestCase
{
    private const KEY = 'pk_test_0123456789abcdef';

    private const BODY = '{"id":"af6029f80f5fc73a8ad2753eea0b1be0","status":"SUCCEEDED","amount":1000}';

    private const HEADER = 't=1763290000,v1=bd14ad016bbfff2704158fd246d3914d8229935ac5ca6898c349193af4d3a09f';

    public function testMoneiSignatureGivesThePaymentId(): void
    {
        self::assertSame('af6029f80f5fc73a8ad2753eea0b1be0', SignedCallback::verify(self::BODY, self::HEADER, self::KEY)->paymentId);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function unsignedCallbacks(): iterable
    {
        yield 'body changed' => [str_replace('1000', '1001', self::BODY), self::HEADER];
        yield 'no header' => [self::BODY, null];
        yield 'no v1' => [self::BODY, 't=1763290000'];
        // MONEI's SDK parser emits a warning on a part without "=".
        yield 'v1 without value' => [self::BODY, 't=1763290000,v1'];
    }

    #[DataProvider('unsignedCallbacks')]
    public function testUnsignedCallbackIsRefusedWith401(string $body, ?string $header): void
    {
        self::assertRefusedWith(401, $body, $header);
    }

    public function testSignedBodyThatIsNotJsonIsRefusedWith400(): void
    {
        self::assertRefusedWith(400, 'not json', 't=1763290000,v1=008aaf44949df2de85961ac3497e366164ead719091577ca44bcfb800a4db336');
    }

    public function testSignedBodyWithoutStringIdIsRefusedWith400(): void
    {
        self::assertRefusedWith(400, '{"id":123}', 't=1763290000,v1=94553cc24fe1b03a9e94f9173e84fea44df488da18b51f450fbaf1c487570a97');
    }

    private static function assertRefusedWith(int $httpStatus, string $body, ?string $header): void
    {
        try {
            SignedCallback::verify($body, $header, self::KEY);
        } catch (InvalidCallbackException $exception) {
            self::assertSame($httpStatus, $exception->httpStatus);

            return;
        }

        self::fail('The callback was accepted.');
    }
}
