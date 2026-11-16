<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Exception;

use Monei\ApiException;
use Monei\SyliusPlugin\Api\MoneiApi;
use Symfony\Component\Workflow\Exception\ExceptionInterface as WorkflowExceptionInterface;

/**
 * MONEI's state forbids a Sylius transition. As a workflow exception it reaches the caller
 * wrapped in StateMachineExecutionException, which the unpaid-orders command catches.
 * The message is a translation key of the `flashes` domain.
 */
final class MoneiRefusedException extends \RuntimeException implements WorkflowExceptionInterface
{
    /** MONEI is running another operation on the payment. */
    private const IN_PROGRESS = ['Request is in progress, please try again later', 'Request already in progress'];

    /** @param array<string, string|int> $parameters */
    public function __construct(
        string $translationKey,
        public readonly array $parameters = [],
        public readonly ?int $orderId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($translationKey, 0, $previous);
    }

    public static function fromApiException(ApiException $e, string $translationKey, ?int $orderId): self
    {
        $message = MoneiApi::errorMessage($e);
        if (\in_array($message, self::IN_PROGRESS, true)) {
            return new self('monei.refused.try_again', [], $orderId, $e);
        }

        return new self($translationKey, ['%reason%' => '' !== $message ? $message : 'HTTP '.$e->getCode()], $orderId, $e);
    }
}
