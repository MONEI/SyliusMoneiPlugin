<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Callback;

use Symfony\Component\HttpFoundation\Response;

/** A callback that changes nothing. CallbackExceptionListener answers it with $httpStatus. */
final class InvalidCallbackException extends \RuntimeException
{
    private function __construct(string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    /** No valid signature: nothing is loaded or changed. */
    public static function unsigned(): self
    {
        return new self('missing or invalid MONEI-Signature', Response::HTTP_UNAUTHORIZED);
    }

    /** Signed, but the request cannot be used. */
    public static function refused(string $reason): self
    {
        return new self($reason, Response::HTTP_BAD_REQUEST);
    }

    /** Signed and valid, but about a payment this store no longer has: MONEI must not retry. */
    public static function ignored(string $reason): self
    {
        return new self($reason, Response::HTTP_NO_CONTENT);
    }
}
