<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Client;

use Monei\ApiException;

/**
 * What the Payum actions need from MONEI. Actions depend on this, not on the
 * SDK, so tests can mock it.
 */
interface MoneiApiClientInterface
{
    /**
     * Create a new MONEI payment.
     *
     * @param array{
     *     amount: int,
     *     currency: string,
     *     orderId: string,
     *     description?: string,
     *     completeUrl: string,
     *     cancelUrl?: string,
     *     callbackUrl: string,
     *     customer?: array{email?: string, name?: string, phone?: string},
     *     billingDetails?: array{name?: string, email?: string, phone?: string, company?: string, address?: array},
     *     shippingDetails?: array{name?: string, email?: string, phone?: string, company?: string, address?: array},
     *     sessionDetails?: array{ip?: string, userAgent?: string},
     * } $params
     *
     * @return array<string, mixed> The created payment as an associative array
     *
     * @throws ApiException
     */
    public function createPayment(array $params): array;

    /**
     * Retrieve a payment by its ID.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function getPayment(string $paymentId): array;

    /**
     * Refund a payment (full or partial).
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function refundPayment(string $paymentId, ?int $amount = null, ?string $reason = null): array;

    /**
     * Cancel a pending payment.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function cancelPayment(string $paymentId): array;

    /**
     * Verify a webhook signature using the SDK's built-in verification.
     *
     * The SDK handles the timestamp-based HMAC signature format that MONEI uses,
     * which includes replay-attack protection.
     *
     * @return object The verified payment object
     *
     * @throws ApiException If the signature is invalid
     */
    public function verifySignature(string $body, string $signature): object;

    public function getApiKey(): string;

    public function getAccountId(): string;
}
