<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Resolver;

use Payum\Core\Request\GetStatusInterface;

/**
 * Maps MONEI payment statuses to Payum/Sylius payment statuses.
 *
 * Extracted from StatusAction for reuse (NotifyAction also needs this)
 * and isolated testability.
 */
final class PaymentStatusResolver
{
    /**
     * @param string $moneiStatus The MONEI payment status string
     * @param GetStatusInterface $request The Payum status request to mark
     */
    public function resolve(string $moneiStatus, GetStatusInterface $request): void
    {
        match ($moneiStatus) {
            'SUCCEEDED' => $request->markCaptured(),
            'AUTHORIZED' => $request->markAuthorized(),
            'PENDING' => $request->markPending(),
            'FAILED' => $request->markFailed(),
            'CANCELED', 'CANCELLED' => $request->markCanceled(),
            'REFUNDED' => $request->markRefunded(),
            'PARTIALLY_REFUNDED' => $request->markCaptured(),
            'EXPIRED' => $request->markExpired(),
            default => $request->markUnknown(),
        };
    }
}
