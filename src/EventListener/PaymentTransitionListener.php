<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\EventListener;

use Monei\ApiException;
use Monei\Model\PaymentStatus;
use Monei\SyliusPlugin\Api\MoneiApi;
use Monei\SyliusPlugin\Exception\MoneiRefusedException;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Payment\BoundPayment;
use Monei\SyliusPlugin\Payment\BoundPaymentAccess;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Monei\SyliusPlugin\Payment\SyncContext;
use Monei\SyliusPlugin\Payment\UnreachableBoundPaymentException;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Admin complete, cancel and refund of a Sylius payment (HTML and API): runs the MONEI
 * capture, cancel or refund first, and refuses the transition when MONEI's state forbids it.
 */
final readonly class PaymentTransitionListener
{
    private const HOLD_GONE = [PaymentStatus::CANCELED, PaymentStatus::EXPIRED, PaymentStatus::FAILED];

    private const REFUNDABLE = [PaymentStatus::SUCCEEDED, PaymentStatus::PARTIALLY_REFUNDED];

    public function __construct(
        private SyncContext $syncContext,
        private PaymentLock $paymentLock,
        private BoundPaymentAccess $boundPayments,
    ) {
    }

    public function __invoke(TransitionEvent $event): void
    {
        // The caller copies MONEI's state and holds the lock; a refresh would discard its unflushed changes.
        if ($this->syncContext->isActive()) {
            return;
        }

        $payment = $event->getSubject();
        // Another gateway's payment: the lock's refresh would discard that gateway's unflushed changes.
        if (!$payment instanceof PaymentInterface || (!self::hasMoneiMethod($payment) && null === MoneiDetails::paymentId($payment))) {
            return;
        }

        $state = $payment->getState();
        $this->paymentLock->acquire($payment);
        $orderId = self::orderId($payment);
        if ($payment->getState() !== $state) {
            throw new MoneiRefusedException('monei.refused.state_changed', [], $orderId);
        }

        // An offline settlement: no MONEI payment to act on.
        if (!self::hasMoneiMethod($payment) || null === MoneiDetails::paymentId($payment)) {
            return;
        }

        match ($event->getTransition()?->getName()) {
            PaymentTransitions::TRANSITION_COMPLETE => $this->complete($payment, $orderId),
            PaymentTransitions::TRANSITION_CANCEL => $this->cancel($payment, $orderId),
            PaymentTransitions::TRANSITION_REFUND => $this->refund($payment, $orderId),
            default => null,
        };
    }

    private function complete(PaymentInterface $payment, ?int $orderId): void
    {
        $bound = $this->fetch($payment, $orderId);
        $status = MoneiApi::status($bound->payment);
        if (!\in_array($status, [PaymentStatus::SUCCEEDED, PaymentStatus::AUTHORIZED], true)) {
            throw new MoneiRefusedException('monei.refused.not_paid', ['%status%' => $status], $orderId);
        }
        if (!StatusApplier::amountMatches($payment, $bound->payment)) {
            throw new MoneiRefusedException('monei.refused.amount_mismatch', [], $orderId);
        }
        // SUCCEEDED: captured in the MONEI dashboard.
        if (PaymentStatus::AUTHORIZED !== $status) {
            return;
        }

        try {
            $captured = $bound->api->capturePayment((string) $bound->payment->getId());
        } catch (ApiException $e) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.capture', $orderId);
        }
        if (PaymentStatus::SUCCEEDED !== MoneiApi::status($captured)) {
            throw new MoneiRefusedException('monei.refused.capture', ['%reason%' => MoneiApi::status($captured)], $orderId);
        }
    }

    /** Reached only through an order cancel, after OrderCancelGuardListener. */
    private function cancel(PaymentInterface $payment, ?int $orderId): void
    {
        // The guard already cancelled a PENDING MONEI payment.
        if (PaymentInterface::STATE_AUTHORIZED !== $payment->getState()) {
            return;
        }

        try {
            $bound = $this->boundPayments->fetch($payment);
        } catch (UnreachableBoundPaymentException) {
            // Logged at critical level. The hold expires at MONEI.
            return;
        } catch (ApiException $e) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.unavailable', $orderId);
        }
        if (null === $bound) {
            throw new \LogicException('A bound MONEI payment is expected.');
        }

        $status = MoneiApi::status($bound->payment);
        if (\in_array($status, self::HOLD_GONE, true)) {
            return;
        }
        if (PaymentStatus::AUTHORIZED !== $status) {
            throw new MoneiRefusedException('monei.refused.cannot_cancel', ['%status%' => $status], $orderId);
        }

        try {
            $bound->api->cancelPayment((string) $bound->payment->getId());
        } catch (ApiException $e) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.cancel', $orderId);
        }
    }

    private function refund(PaymentInterface $payment, ?int $orderId): void
    {
        $bound = $this->fetch($payment, $orderId);
        $status = MoneiApi::status($bound->payment);
        if (PaymentStatus::REFUNDED === $status) {
            return;
        }
        if (!\in_array($status, self::REFUNDABLE, true)) {
            throw new MoneiRefusedException('monei.refused.not_refundable', ['%status%' => $status], $orderId);
        }

        $amount = (int) $bound->payment->getAmount() - (int) $bound->payment->getRefundedAmount();

        try {
            $bound->api->refundPayment((string) $bound->payment->getId(), $amount);
        } catch (ApiException $e) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.refund', $orderId);
        }
    }

    /** A key change must not turn a capture or a refund into a Sylius-only change. */
    private function fetch(PaymentInterface $payment, ?int $orderId): BoundPayment
    {
        try {
            $bound = $this->boundPayments->fetch($payment);
        } catch (UnreachableBoundPaymentException $e) {
            throw new MoneiRefusedException('monei.refused.unreachable', ['%payment_method%' => (string) MoneiDetails::paymentMethodCode($payment)], $orderId, $e);
        } catch (ApiException $e) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.unavailable', $orderId);
        }
        if (null === $bound) {
            throw new \LogicException('A bound MONEI payment is expected.');
        }

        return $bound;
    }

    private static function hasMoneiMethod(PaymentInterface $payment): bool
    {
        return GatewayConfiguration::FACTORY_NAME === $payment->getMethod()?->getGatewayConfig()?->getFactoryName();
    }

    private static function orderId(PaymentInterface $payment): ?int
    {
        $id = $payment->getOrder()?->getId();

        return \is_int($id) ? $id : null;
    }
}
