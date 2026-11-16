<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\RefundPlugin;

use Monei\ApiException;
use Monei\Model\PaymentStatus;
use Monei\SyliusPlugin\Api\MoneiApi;
use Monei\SyliusPlugin\Exception\MoneiRefusedException;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Payment\BoundPaymentAccess;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\UnreachableBoundPaymentException;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\RefundPlugin\Event\UnitsRefunded;
use Sylius\RefundPlugin\ProcessManager\UnitsRefundedProcessStepInterface;
use Sylius\RefundPlugin\Provider\OrderRefundedTotalProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Refunds the refund plugin's total at MONEI when the chosen refund method is a MONEI one.
 * Runs inside the refund command's transaction, after the plugin's own steps: a refusal
 * rolls back the credit memo and the refund payment, and once MONEI has returned the money
 * only the commit can still fail.
 */
final readonly class MoneiRefundStep implements UnitsRefundedProcessStepInterface
{
    private const REFUNDABLE = [PaymentStatus::SUCCEEDED, PaymentStatus::PARTIALLY_REFUNDED];

    /**
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     * @param OrderRepositoryInterface<OrderInterface>                 $orderRepository
     */
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
        private OrderRepositoryInterface $orderRepository,
        private OrderRefundedTotalProviderInterface $orderRefundedTotal,
        private PaymentLock $paymentLock,
        private BoundPaymentAccess $boundPayments,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    public function next(UnitsRefunded $unitsRefunded): void
    {
        $paymentMethod = $this->paymentMethodRepository->find($unitsRefunded->paymentMethodId());
        if (!$paymentMethod instanceof PaymentMethodInterface || !GatewayConfiguration::isMonei($paymentMethod)) {
            return;
        }

        $order = $this->orderRepository->findOneByNumber($unitsRefunded->orderNumber());
        if (!$order instanceof OrderInterface) {
            throw new \LogicException(sprintf('Order "%s" not found.', $unitsRefunded->orderNumber()));
        }

        try {
            $this->refund($order, $paymentMethod, $unitsRefunded->amount());
        } catch (MoneiRefusedException $e) {
            $this->logger->error('Refund of order {order} refused: {reason}', [
                'order' => $order->getNumber(),
                'reason' => $e->getMessage(),
                'parameters' => $e->parameters,
            ]);
            // The refund plugin's action shows only a generic error for a failed step.
            $this->flash($e);

            throw $e;
        }
    }

    private function refund(OrderInterface $order, PaymentMethodInterface $paymentMethod, int $amount): void
    {
        $orderId = \is_int($order->getId()) ? $order->getId() : null;
        $payment = self::paidPayment($order, $paymentMethod);
        if (null === $payment) {
            throw new MoneiRefusedException('monei.refused.refund_method_not_paid', ['%payment_method%' => (string) $paymentMethod->getName()], $orderId);
        }

        $this->paymentLock->acquire($payment);

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

        $status = MoneiApi::status($bound->payment);
        if (!\in_array($status, self::REFUNDABLE, true)) {
            throw new MoneiRefusedException('monei.refused.not_refundable', ['%status%' => $status], $orderId);
        }

        // The refund plugin ties every refund of an order to the order's paid payment, and
        // has already recorded this one.
        $recorded = ($this->orderRefundedTotal)($order) - $amount;
        if ((int) $bound->payment->getRefundedAmount() > $recorded) {
            throw new MoneiRefusedException('monei.refused.refund_answer_lost', [], $orderId);
        }

        try {
            $bound->api->refundPayment((string) $bound->payment->getId(), $amount);
        } catch (ApiException $e) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.refund', $orderId);
        }
    }

    /** The order's completed payment that `$paymentMethod` paid through MONEI. */
    private static function paidPayment(OrderInterface $order, PaymentMethodInterface $paymentMethod): ?PaymentInterface
    {
        foreach (array_reverse($order->getPayments()->toArray()) as $payment) {
            if (
                $payment instanceof PaymentInterface
                && PaymentInterface::STATE_COMPLETED === $payment->getState()
                && $payment->getMethod()?->getId() === $paymentMethod->getId()
                && null !== MoneiDetails::paymentId($payment)
            ) {
                return $payment;
            }
        }

        return null;
    }

    private function flash(MoneiRefusedException $e): void
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', ['message' => $e->getMessage(), 'parameters' => $e->parameters]);
        }
    }
}
