<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\EventListener;

use Monei\ApiException;
use Monei\Model\PaymentStatus;
use Monei\SyliusPlugin\Api\MoneiApi;
use Monei\SyliusPlugin\Exception\MoneiRefusedException;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Payment\BoundPaymentAccess;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Monei\SyliusPlugin\Payment\SyncContext;
use Monei\SyliusPlugin\Payment\UnreachableBoundPaymentException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Complete, cancel and refund of a Sylius payment (admin HTML and API, unpaid-orders command):
 * runs the MONEI capture, cancel or refund first, and refuses the transition when MONEI's
 * state forbids it.
 */
final readonly class PaymentTransitionListener
{
    private const HOLD_GONE = [PaymentStatus::CANCELED, PaymentStatus::EXPIRED, PaymentStatus::FAILED];

    public function __construct(
        private SyncContext $syncContext,
        private PaymentLock $paymentLock,
        private BoundPaymentAccess $boundPayments,
    ) {
    }

    public function __invoke(TransitionEvent $event): void
    {
        // The caller copies MONEI's state; a refresh would discard its unflushed changes.
        if ($this->syncContext->isActive()) {
            return;
        }

        $payment = $event->getSubject();
        // Another gateway's payment: the lock's refresh would discard that gateway's unflushed changes.
        if (!$payment instanceof PaymentInterface || !GatewayConfiguration::isMonei($payment->getMethod())) {
            return;
        }
        $order = $payment->getOrder();
        if (!$order instanceof OrderInterface) {
            throw new \LogicException('A payment with an order is expected.');
        }

        $state = $payment->getState();
        $this->paymentLock->acquire($payment);
        if ($payment->getState() !== $state) {
            throw new MoneiRefusedException('monei.refused.state_changed', [], $order);
        }

        // The refresh may show a switch to another gateway, or an offline settlement: no MONEI payment to act on.
        // Read into a local: PHPStan remembers the check above and would call this one always false.
        $method = $payment->getMethod();
        if (!GatewayConfiguration::isMonei($method) || null === MoneiDetails::paymentId($payment)) {
            return;
        }

        match ($event->getTransition()?->getName()) {
            PaymentTransitions::TRANSITION_COMPLETE => $this->complete($payment, $order),
            PaymentTransitions::TRANSITION_CANCEL => $this->cancel($payment, $order),
            PaymentTransitions::TRANSITION_REFUND => $this->refund($payment, $order),
            default => null,
        };
    }

    private function complete(PaymentInterface $payment, OrderInterface $order): void
    {
        $bound = $this->boundPayments->fetchOrRefuse($payment, $order);
        $status = MoneiApi::status($bound->payment);
        if (!\in_array($status, [PaymentStatus::SUCCEEDED, PaymentStatus::AUTHORIZED], true)) {
            throw new MoneiRefusedException('monei.refused.not_paid', ['%status%' => $status], $order);
        }
        if (!StatusApplier::amountMatches($payment, $bound->payment)) {
            throw new MoneiRefusedException('monei.refused.amount_mismatch', [], $order);
        }
        // SUCCEEDED: captured in the MONEI dashboard.
        if (PaymentStatus::AUTHORIZED !== $status) {
            return;
        }

        try {
            $captured = $bound->api->capturePayment((string) $bound->payment->getId());
        } catch (ApiException $e) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.capture', $order);
        }
        if (PaymentStatus::SUCCEEDED !== MoneiApi::status($captured)) {
            throw new MoneiRefusedException('monei.refused.capture', ['%reason%' => MoneiApi::status($captured)], $order);
        }
    }

    /** Reached only through an order cancel, after OrderCancelGuardListener. */
    private function cancel(PaymentInterface $payment, OrderInterface $order): void
    {
        // The guard handled a PENDING MONEI payment.
        if (PaymentInterface::STATE_AUTHORIZED !== $payment->getState()) {
            return;
        }

        try {
            $bound = $this->boundPayments->fetchBound($payment);
        } catch (UnreachableBoundPaymentException) {
            // Logged at critical level. The hold expires at MONEI.
            return;
        } catch (ApiException $e) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.unavailable', $order);
        }

        $status = MoneiApi::status($bound->payment);
        if (\in_array($status, self::HOLD_GONE, true)) {
            return;
        }
        if (PaymentStatus::AUTHORIZED !== $status) {
            throw new MoneiRefusedException('monei.refused.cannot_cancel', ['%status%' => $status], $order);
        }

        try {
            $bound->api->cancelPayment((string) $bound->payment->getId());
        } catch (ApiException $e) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.cancel', $order);
        }
    }

    private function refund(PaymentInterface $payment, OrderInterface $order): void
    {
        $bound = $this->boundPayments->fetchOrRefuse($payment, $order);
        $status = MoneiApi::status($bound->payment);
        if (PaymentStatus::REFUNDED === $status) {
            return;
        }
        if (!\in_array($status, MoneiApi::REFUNDABLE, true)) {
            throw new MoneiRefusedException('monei.refused.not_refundable', ['%status%' => $status], $order);
        }

        $amount = (int) $bound->payment->getAmount() - (int) $bound->payment->getRefundedAmount();

        $this->boundPayments->refundOrRefuse($bound, $amount, $order);
    }
}
