<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Exception;

use Monei\ApiException;
use Monei\SyliusPlugin\Api\MoneiApi;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Workflow\Exception\ExceptionInterface as WorkflowExceptionInterface;

/**
 * MONEI's state forbids a Sylius action. Thrown in a transition, as a workflow exception it
 * reaches the caller wrapped in StateMachineExecutionException, which the unpaid-orders
 * command catches. The message is a translation key of the `flashes` domain.
 */
final class MoneiRefusedException extends \RuntimeException implements WorkflowExceptionInterface
{
    /** MONEI is running another operation on the payment. */
    private const IN_PROGRESS = ['Request is in progress, please try again later', 'Request already in progress'];

    public readonly int $orderId;

    /** @param array<string, string|int> $parameters */
    public function __construct(
        string $translationKey,
        public readonly array $parameters,
        OrderInterface $order,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($translationKey, 0, $previous);
        $this->orderId = (int) $order->getId();
    }

    public static function fromApiException(ApiException $e, string $translationKey, OrderInterface $order): self
    {
        if (\in_array(MoneiApi::errorMessage($e), self::IN_PROGRESS, true)) {
            return new self('monei.refused.try_again', [], $order, $e);
        }

        return new self($translationKey, ['%reason%' => MoneiApi::reason($e)], $order, $e);
    }
}
