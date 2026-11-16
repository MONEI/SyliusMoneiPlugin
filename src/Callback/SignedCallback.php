<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Callback;

/**
 * A MONEI payment callback whose `MONEI-Signature` matches the API key. The body only
 * points to a payment: its status is always fetched from MONEI.
 */
final readonly class SignedCallback
{
    private function __construct(public string $paymentId)
    {
    }

    /**
     * @throws InvalidCallbackException when the signature does not match or the body names no payment
     */
    public static function verify(string $body, ?string $signatureHeader, string $apiKey): self
    {
        if (null === $signatureHeader || !self::signatureMatches($body, $signatureHeader, $apiKey)) {
            throw InvalidCallbackException::unsigned();
        }

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidCallbackException::refused('the signed body is not JSON');
        }

        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if (!is_string($id) || '' === $id) {
            throw InvalidCallbackException::refused('the signed body has no payment id');
        }

        return new self($id);
    }

    /** Header format: `t=<timestamp>,v1=<hex HMAC-SHA256 of "<timestamp>.<body>">`. */
    private static function signatureMatches(string $body, string $header, string $apiKey): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (2 === count($pair)) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        if ('' === $timestamp || '' === $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $apiKey), $signature);
    }
}
