<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Monei\Model\Payment as MoneiPayment;
use Monei\Model\PaymentStatus;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Copies the status of the MONEI payment (always fetched from the MONEI API) to the
 * Sylius payment it is bound to. The caller holds the payment lock.
 */
final readonly class StatusApplier
{
    /**
     * Transitions to try, in order, for each MONEI status. The state machine decides
     * which one applies to the payment's current state; none is applied twice.
     */
    private const TRANSITIONS = [
        PaymentStatus::SUCCEEDED => [PaymentTransitions::TRANSITION_COMPLETE],
        PaymentStatus::AUTHORIZED => [PaymentTransitions::TRANSITION_AUTHORIZE],
        PaymentStatus::FAILED => [PaymentTransitions::TRANSITION_FAIL],
        PaymentStatus::CANCELED => [PaymentTransitions::TRANSITION_CANCEL],
        // An expired AUTH hold cannot fail (Sylius allows fail only from new or processing).
        PaymentStatus::EXPIRED => [PaymentTransitions::TRANSITION_FAIL, PaymentTransitions::TRANSITION_CANCEL],
        // A full refund made in the MONEI dashboard. Completes first when the
        // SUCCEEDED callback never reached the store.
        PaymentStatus::REFUNDED => [PaymentTransitions::TRANSITION_COMPLETE, PaymentTransitions::TRANSITION_REFUND],
    ];

    /** The statuses that mean MONEI took or holds the money. */
    private const PAID = [PaymentStatus::SUCCEEDED, PaymentStatus::AUTHORIZED, PaymentStatus::REFUNDED];

    public function __construct(
        private StateMachineInterface $stateMachine,
        private SyncContext $syncContext,
        private LoggerInterface $logger,
    ) {
    }

    public function apply(PaymentInterface $payment, MoneiPayment $moneiPayment): void
    {
        $moneiPaymentId = (string) $moneiPayment->getId();
        $status = (string) $moneiPayment->getStatus();

        if (MoneiDetails::paymentId($payment) !== $moneiPaymentId) {
            $level = in_array($status, self::PAID, true) ? 'error' : 'warning';
            $this->logger->log($level, 'MONEI payment {monei_payment} ({status}) is not bound to Sylius payment {payment}: no change. If no other payment of the order holds the money, cancel or refund it in the MONEI dashboard.', [
                'monei_payment' => $moneiPaymentId,
                'status' => $status,
                'payment' => $payment->getId(),
            ]);

            return;
        }

        MoneiDetails::updateStatus($payment, $status);

        if (in_array($status, self::PAID, true) && !$this->amountMatches($payment, $moneiPayment)) {
            $this->logger->critical('MONEI payment {monei_payment} is {amount} {currency}, but Sylius payment {payment} is {expected_amount} {expected_currency}: no change.', [
                'monei_payment' => $moneiPaymentId,
                'amount' => $moneiPayment->getAmount(),
                'currency' => $moneiPayment->getCurrency(),
                'payment' => $payment->getId(),
                'expected_amount' => $payment->getAmount(),
                'expected_currency' => $payment->getCurrencyCode(),
            ]);

            return;
        }

        $this->syncContext->run(function () use ($payment, $status): void {
            foreach (self::TRANSITIONS[$status] ?? [] as $transition) {
                if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
                    $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
                }
            }
        });
    }

    private function amountMatches(PaymentInterface $payment, MoneiPayment $moneiPayment): bool
    {
        return $moneiPayment->getAmount() === $payment->getAmount()
            && strtoupper((string) $moneiPayment->getCurrency()) === strtoupper((string) $payment->getCurrencyCode());
    }
}
