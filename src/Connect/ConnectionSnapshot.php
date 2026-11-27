<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Connect;

final readonly class ConnectionSnapshot
{
    /** @param array<string, mixed>|null $lastResult */
    public function __construct(
        public ?string $liveAccountId,
        public ?string $liveApiKey,
        public ?string $liveSigningSecret,
        public ?string $testAccountId,
        public ?string $testApiKey,
        public ?string $testSigningSecret,
        public int $generation,
        public ?array $lastResult,
    ) {
    }
}
