<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\CommandHandler;

use Monei\ApiException;
use Monei\SyliusPlugin\Command\StatusPaymentRequest;
use Monei\SyliusPlugin\Payment\BoundPaymentAccess;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Monei\SyliusPlugin\Payment\UnreachableBoundPaymentException;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Exception\PaymentRequestNotFoundException;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;

/**
 * The return page: copies the status of the bound MONEI payment to the Sylius payment.
 * A fetch error fails only the status request and returns normally: a throw would show
 * a 500 page, and the payment stays open for its callback.
 */
final readonly class StatusPaymentRequestHandler
{
    /** @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository */
    public function __construct(
        private PaymentRequestRepositoryInterface $paymentRequestRepository,
        private PaymentLock $paymentLock,
        private BoundPaymentAccess $boundPayments,
        private StatusApplier $statusApplier,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StatusPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestRepository->find($command->hash);
        if (!$paymentRequest instanceof PaymentRequestInterface) {
            throw new PaymentRequestNotFoundException();
        }
        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException('A Sylius core payment is expected.');
        }

        $this->paymentLock->acquire($payment);

        try {
            $bound = $this->boundPayments->fetch($payment);
        } catch (UnreachableBoundPaymentException) {
            $bound = null;
        } catch (ApiException $e) {
            $this->logger->error('Fetching MONEI payment {monei_payment} of Sylius payment {payment} on the return page failed ({code}): the payment is unchanged.', [
                'monei_payment' => MoneiDetails::paymentId($payment),
                'payment' => $payment->getId(),
                'code' => $e->getCode(),
            ]);
            $this->apply($paymentRequest, PaymentRequestTransitions::TRANSITION_FAIL);

            return;
        }

        if (null !== $bound) {
            $this->statusApplier->apply($payment, $bound->payment);
        }

        $this->apply($paymentRequest, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function apply(PaymentRequestInterface $paymentRequest, string $transition): void
    {
        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, $transition);
        }
    }
}
