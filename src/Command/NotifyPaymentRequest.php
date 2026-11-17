<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Command;

final readonly class NotifyPaymentRequest
{
    public function __construct(public string $hash)
    {
    }
}
