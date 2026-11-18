<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Payment;

use Monei\SyliusPlugin\Payment\SessionId;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;

final class SessionIdTest extends TestCase
{
    /** A shared session ID would let a token made for one order pay another order's payment. */
    public function testEachOrderGetsItsOwnSessionIdInTheFormatMoneiAccepts(): void
    {
        $sessionId = new SessionId('secret');

        $first = $sessionId->forOrder($this->order(1));
        $second = $sessionId->forOrder($this->order(2));

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,40}$/D', $first);
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,40}$/D', $second);
    }

    /** Without the secret, anyone could compute the session ID of any order from its ID. */
    public function testTheSessionIdDependsOnTheSecret(): void
    {
        self::assertNotSame((new SessionId('a'))->forOrder($this->order(1)), (new SessionId('b'))->forOrder($this->order(1)));
    }

    /** Every unsaved order has the null ID: they would all share one session ID. */
    public function testAnOrderWithoutIdIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        (new SessionId('secret'))->forOrder($this->order(null));
    }

    private function order(?int $id): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getId')->willReturn($id);

        return $order;
    }
}
