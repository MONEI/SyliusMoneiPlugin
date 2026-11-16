<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Command;

final readonly class CapturePaymentRequest
{
    public function __construct(public string $hash)
    {
    }
}
