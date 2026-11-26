<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Entity;

class MoneiConnection
{
    /** @param array<string, mixed>|null $lastResult */
    public function __construct(
        public readonly int $id,
        public readonly ?string $liveAccountId,
        public readonly ?string $liveApiKey,
        public readonly ?string $liveSigningSecret,
        public readonly ?string $testAccountId,
        public readonly ?string $testApiKey,
        public readonly ?string $testSigningSecret,
        public readonly int $generation,
        public readonly ?array $lastResult,
    ) {
    }
}
