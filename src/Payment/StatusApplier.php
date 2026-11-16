<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Monei\Model\Payment as MoneiPayment;
use Monei\Model\PaymentStatus;
use Monei\SyliusPlugin\Api\MoneiApi;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Copies the status of the MONEI payment (always fetched from the MONEI API) to the
 * Sylius payment it is bound to. The caller has called PaymentLock::acquire.
 */
final readonly class StatusApplier
{
    /**
     * Transitions to try, in order, for each MONEI status. The state machine decides
     * which one applies to the payment's current state.
     */
    private const TRANSITIONS = [
        PaymentStatus::SUCCEEDED => [PaymentTransitions::TRANSITION_COMPLETE],
        PaymentStatus::AUTHORIZED => [PaymentTransitions::TRANSITION_AUTHORIZE],
        PaymentStatus::FAILED => [PaymentTransitions::TRANSITION_FAIL],
        PaymentStatus::CANCELED => [PaymentTransitions::TRANSITION_CANCEL],
        PaymentStatus::EXPIRED => [PaymentTransitions::TRANSITION_FAIL],
        // Complete first: the SUCCEEDED callback may never have reached the store.
        PaymentStatus::REFUNDED => [PaymentTransitions::TRANSITION_COMPLETE, PaymentTransitions::TRANSITION_REFUND],
        // Sylius has no partially refunded state.
        PaymentStatus::PARTIALLY_REFUNDED => [PaymentTransitions::TRANSITION_COMPLETE],
    ];

    /** MONEI holds or took money. */
    private const MONEY = [PaymentStatus::SUCCEEDED, PaymentStatus::AUTHORIZED, PaymentStatus::REFUNDED, PaymentStatus::PARTIALLY_REFUNDED];

    public function __construct(
        private StateMachineInterface $stateMachine,
        private SyncContext $syncContext,
        private LoggerInterface $logger,
    ) {
    }

    public function apply(PaymentInterface $payment, MoneiPayment $moneiPayment): void
    {
        $moneiPaymentId = (string) $moneiPayment->getId();
        $status = MoneiApi::status($moneiPayment);
        $holdsMoney = \in_array($status, self::MONEY, true);
        $context = ['monei_payment' => $moneiPaymentId, 'status' => $status, 'payment' => $payment->getId()];

        if (MoneiDetails::paymentId($payment) !== $moneiPaymentId) {
            $this->logger->log(
                $holdsMoney ? 'critical' : 'warning',
                'MONEI payment {monei_payment} ({status}) is not bound to Sylius payment {payment}: no change. If no other payment of the order holds the money, cancel or refund it in the MONEI dashboard.',
                $context,
            );

            return;
        }

        // The buyer switched to a non-MONEI method after the MONEI payment was bound.
        if (!GatewayConfiguration::isMonei($payment->getMethod())) {
            $this->logger->log(
                $holdsMoney ? 'critical' : 'warning',
                'MONEI payment {monei_payment} ({status}) is bound to Sylius payment {payment}, which now uses a non-MONEI payment method: no change. If it holds money, refund it in the MONEI dashboard.',
                $context,
            );

            return;
        }

        if (\in_array($status, MoneiApi::PENDING, true)) {
            return;
        }

        if (!isset(self::TRANSITIONS[$status])) {
            $this->logger->warning('MONEI payment {monei_payment} has status {status}, which the plugin does not map: Sylius payment {payment} unchanged.', $context);

            return;
        }

        if ($holdsMoney && !self::amountMatches($payment, $moneiPayment)) {
            $this->logger->critical('MONEI payment {monei_payment} is {amount} {currency}, but Sylius payment {payment} is {expected_amount} {expected_currency}: no change.', $context + [
                'amount' => $moneiPayment->getAmount(),
                'currency' => $moneiPayment->getCurrency(),
                'expected_amount' => $payment->getAmount(),
                'expected_currency' => $payment->getCurrencyCode(),
            ]);

            return;
        }

        $this->syncContext->run(function () use ($payment, $status): void {
            foreach (self::TRANSITIONS[$status] as $transition) {
                if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
                    $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
                }
            }
        });

        if ($holdsMoney && \in_array($payment->getState(), [PaymentInterface::STATE_FAILED, PaymentInterface::STATE_CANCELLED], true)) {
            $this->logger->critical('MONEI payment {monei_payment} is {status}, but Sylius payment {payment} is {state}: MONEI holds money for it. Refund it in the MONEI dashboard or restore the order by hand.', $context + [
                'state' => $payment->getState(),
            ]);
        }
    }

    public static function amountMatches(PaymentInterface $payment, MoneiPayment $moneiPayment): bool
    {
        return $moneiPayment->getAmount() === $payment->getAmount()
            && strtoupper((string) $moneiPayment->getCurrency()) === strtoupper((string) $payment->getCurrencyCode());
    }
}
