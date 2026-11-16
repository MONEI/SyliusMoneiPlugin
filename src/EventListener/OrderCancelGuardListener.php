<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\EventListener;

use Monei\ApiException;
use Monei\Model\PaymentStatus;
use Monei\SyliusPlugin\Api\MoneiApi;
use Monei\SyliusPlugin\Exception\MoneiRefusedException;
use Monei\SyliusPlugin\Payment\BoundPayment;
use Monei\SyliusPlugin\Payment\BoundPaymentAccess;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Monei\SyliusPlugin\Payment\UnreachableBoundPaymentException;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Runs before an order is cancelled (admin, API, unpaid-orders command). When MONEI took money
 * the store has not recorded, a request is refused; the unpaid-orders command records the
 * payment, or logs at critical level and lets the cancel go on. Cancels a PENDING MONEI payment
 * so that the buyer cannot pay a cancelled order. The release of an AUTHORIZED hold is left to
 * PaymentTransitionListener.
 */
final readonly class OrderCancelGuardListener
{
    private const OPEN = [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING, PaymentInterface::STATE_AUTHORIZED];

    private const PAID = [PaymentStatus::SUCCEEDED, PaymentStatus::PARTIALLY_REFUNDED];

    private const PENDING_WARNING_FLASH = 'monei.order.payment_still_pending';

    public function __construct(
        private PaymentLock $paymentLock,
        private BoundPaymentAccess $boundPayments,
        private StatusApplier $statusApplier,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TransitionEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            return;
        }

        foreach ($order->getPayments() as $payment) {
            if ($payment instanceof PaymentInterface && \in_array($payment->getState(), self::OPEN, true) && null !== MoneiDetails::paymentId($payment)) {
                $this->check($order, $payment);
            }
        }
    }

    private function check(OrderInterface $order, PaymentInterface $payment): void
    {
        // Decisions rest on MONEI's status, fetched after the lock, not on the Sylius state read before it.
        $this->paymentLock->acquire($payment);

        try {
            $bound = $this->boundPayments->fetchBound($payment);
        } catch (UnreachableBoundPaymentException) {
            // Logged at critical level. The payment may still be payable in the other MONEI account.
            return;
        } catch (ApiException $e) {
            $this->fetchFailed($order, $payment, $e);

            return;
        }

        $this->decide($order, $payment, $bound, true);
    }

    private function decide(OrderInterface $order, PaymentInterface $payment, BoundPayment $bound, bool $mayCancel): void
    {
        $status = MoneiApi::status($bound->payment);
        $unpaidInSylius = PaymentInterface::STATE_AUTHORIZED !== $payment->getState();

        // An AUTHORIZED hold on a new or processing payment is one the store never recorded.
        if (\in_array($status, self::PAID, true) || (PaymentStatus::AUTHORIZED === $status && $unpaidInSylius)) {
            $this->paid($order, $payment, $bound, $status);

            return;
        }
        if (!$unpaidInSylius || !\in_array($status, MoneiApi::PENDING, true)) {
            return;
        }

        $moneiPaymentId = (string) $bound->payment->getId();
        $context = ['monei_payment' => $moneiPaymentId, 'payment' => $payment->getId(), 'order' => $order->getNumber()];
        if (!$mayCancel) {
            // With the processor (for example an open Multibanco reference): the order can always be cancelled.
            $this->logger->warning('MONEI payment {monei_payment} of Sylius payment {payment} cannot be cancelled: order {order} is cancelled, and the MONEI payment stays payable until it expires. Refund it if it is paid.', $context);
            Flash::add($this->requestStack, 'warning', self::PENDING_WARNING_FLASH);

            return;
        }

        try {
            $bound->api->cancelPayment($moneiPaymentId);

            return;
        } catch (ApiException) {
            // Fetched again below: it may have been paid meanwhile.
        }

        try {
            $refetched = new BoundPayment($bound->api->getPayment($moneiPaymentId), $bound->api);
        } catch (ApiException $e) {
            $this->fetchFailed($order, $payment, $e);

            return;
        }

        $this->decide($order, $payment, $refetched, false);
    }

    /** MONEI holds or took money for a payment the store has not recorded as paid. */
    private function paid(OrderInterface $order, PaymentInterface $payment, BoundPayment $bound, string $status): void
    {
        // A request refuses without the sync: the rollback would discard it.
        if (null !== $this->requestStack->getMainRequest()) {
            throw new MoneiRefusedException('monei.refused.order_paid', ['%status%' => $status], $order);
        }

        $this->statusApplier->apply($payment, $bound->payment);
        if (OrderPaymentStates::STATE_AWAITING_PAYMENT !== $order->getPaymentState()) {
            throw new MoneiRefusedException('monei.refused.order_paid', ['%status%' => $status], $order);
        }

        // Refusing would leave the order unpaid, and the unpaid-orders command would loop on it forever.
        $this->logger->critical('MONEI payment {monei_payment} of Sylius payment {payment} is {status}, but the payment could not be recorded: order {order} is cancelled. Refund it in the MONEI dashboard or restore the order by hand.', [
            'monei_payment' => $bound->payment->getId(),
            'payment' => $payment->getId(),
            'status' => $status,
            'order' => $order->getNumber(),
        ]);
    }

    private function fetchFailed(OrderInterface $order, PaymentInterface $payment, ApiException $e): void
    {
        if (null !== $this->requestStack->getMainRequest()) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.unavailable', $order);
        }

        // The unpaid-orders command must end; a paid callback on the cancelled payment logs at critical level.
        $this->logger->error('Fetching MONEI payment {monei_payment} of Sylius payment {payment} failed ({code}): order {order} is cancelled without checking it.', [
            'monei_payment' => MoneiDetails::paymentId($payment),
            'payment' => $payment->getId(),
            'code' => $e->getCode(),
            'order' => $order->getNumber(),
        ]);
    }
}
