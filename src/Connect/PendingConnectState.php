<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Connect;

final readonly class PendingConnectState
{
    public function __construct(
        public string $state,
        public string $verifier,
        public string $redirectUri,
        public int $generation,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
