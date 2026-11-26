<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Entity;

class MoneiCallbackAttempt
{
    public function __construct(
        public readonly int $id,
        public readonly int $paymentId,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
