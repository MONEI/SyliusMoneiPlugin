<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Entity;

class MoneiConnectState
{
    public function __construct(
        public readonly string $state,
        public readonly string $verifier,
        public readonly string $redirectUri,
        public readonly int $generation,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
