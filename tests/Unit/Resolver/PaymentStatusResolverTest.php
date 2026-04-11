<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Resolver;

use Monei\SyliusPlugin\Resolver\PaymentStatusResolver;
use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;

final class PaymentStatusResolverTest extends TestCase
{
    private PaymentStatusResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PaymentStatusResolver();
    }

    /**
     * @dataProvider statusMappingProvider
     */
    public function testItMapsMoneiStatusCorrectly(string $moneiStatus, string $expectedMethod): void
    {
        $payment = new Payment();
        $status = new GetHumanStatus($payment);

        $this->resolver->resolve($moneiStatus, $status);

        $this->assertTrue(
            $status->{'is' . ucfirst($expectedMethod)}(),
            sprintf('Expected status "%s" for MONEI status "%s"', $expectedMethod, $moneiStatus),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function statusMappingProvider(): array
    {
        return [
            'SUCCEEDED maps to captured' => ['SUCCEEDED', 'captured'],
            'AUTHORIZED maps to authorized' => ['AUTHORIZED', 'authorized'],
            'PENDING maps to pending' => ['PENDING', 'pending'],
            'FAILED maps to failed' => ['FAILED', 'failed'],
            'CANCELED maps to canceled' => ['CANCELED', 'canceled'],
            'CANCELLED maps to canceled' => ['CANCELLED', 'canceled'],
            'REFUNDED maps to refunded' => ['REFUNDED', 'refunded'],
            'PARTIALLY_REFUNDED maps to captured' => ['PARTIALLY_REFUNDED', 'captured'],
            'EXPIRED maps to expired' => ['EXPIRED', 'expired'],
            'UNKNOWN maps to unknown' => ['SOME_UNKNOWN_STATUS', 'unknown'],
        ];
    }
}
