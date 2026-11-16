<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\RefundPlugin;

use Monei\ApiException;
use Monei\SyliusPlugin\Api\MoneiApi;
use Monei\SyliusPlugin\EventListener\Flash;
use Monei\SyliusPlugin\Exception\MoneiRefusedException;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Payment\BoundPaymentAccess;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Payment\PaymentLock;
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

/**
 * Refunds the amount of the refund plugin's refund at MONEI when the chosen refund method is a MONEI one.
 * Runs inside the refund command's transaction, before the plugin's own steps: a refusal
 * rolls back the unit refunds before any credit memo is written or sent. When a later step
 * fails after MONEI returned the money, the next MONEI refund of the order is refused
 * (MONEI refunded more than Sylius recorded).
 */
final readonly class MoneiRefundStep implements UnitsRefundedProcessStepInterface
{
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
            Flash::add($this->requestStack, 'error', ['message' => $e->getMessage(), 'parameters' => $e->parameters]);

            throw $e;
        }
    }

    private function refund(OrderInterface $order, PaymentMethodInterface $paymentMethod, int $amount): void
    {
        $payment = self::paidPayment($order, $paymentMethod);
        if (null === $payment) {
            throw new MoneiRefusedException('monei.refused.refund_method_not_paid', ['%payment_method%' => (string) $paymentMethod->getCode()], $order);
        }

        $this->paymentLock->acquire($payment);
        $bound = $this->boundPayments->fetchOrRefuse($payment, $order);

        $status = MoneiApi::status($bound->payment);
        if (!\in_array($status, MoneiApi::REFUNDABLE, true)) {
            throw new MoneiRefusedException('monei.refused.not_refundable', ['%status%' => $status], $order);
        }

        // The refund plugin ties every refund of an order to the order's paid payment, and
        // has already recorded this one's unit refunds.
        $recorded = ($this->orderRefundedTotal)($order) - $amount;
        if ((int) $bound->payment->getRefundedAmount() > $recorded) {
            throw new MoneiRefusedException('monei.refused.refund_answer_lost', [], $order);
        }

        try {
            $bound->api->refundPayment((string) $bound->payment->getId(), $amount);
        } catch (ApiException $e) {
            throw MoneiRefusedException::fromApiException($e, 'monei.refused.refund', $order);
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
}
