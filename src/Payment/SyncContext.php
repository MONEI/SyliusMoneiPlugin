<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

/**
 * Marks Sylius transitions that copy a state MONEI already has (callback, return page).
 * The transition listener then calls nothing at MONEI: the capture, cancel or refund
 * already happened there.
 */
final class SyncContext
{
    private int $depth = 0;

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function run(callable $callback): mixed
    {
        ++$this->depth;

        try {
            return $callback();
        } finally {
            --$this->depth;
        }
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }
}
