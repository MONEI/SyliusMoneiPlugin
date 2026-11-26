<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Callback;

final readonly class VerifiedCallback
{
    public function __construct(
        public string $paymentId,
        public bool $signed,
        public ?string $apiKey,
    ) {
    }

    public function apiKeyOrFail(): string
    {
        return $this->apiKey ?? throw InvalidCallbackException::unavailable($this->paymentId, 'the payment method has unusable credentials');
    }
}
