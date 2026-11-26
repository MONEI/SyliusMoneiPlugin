<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\CommandHandler;

use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\Callback\InvalidCallbackException;
use Monei\SyliusPlugin\Callback\SignedCallback;
use Monei\SyliusPlugin\Command\NotifyPaymentRequest;
use Monei\SyliusPlugin\Gateway\GatewayConfigurationProvider;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Exception\PaymentRequestNotFoundException;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;

/**
 * A MONEI callback: fetches the signed MONEI payment with the API key that signed it (the
 * route's payment method) and copies its status. A fetch error is thrown, so MONEI gets a
 * 5xx and retries.
 */
final readonly class NotifyPaymentRequestHandler
{
    /** @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository */
    public function __construct(
        private PaymentRequestRepositoryInterface $paymentRequestRepository,
        private PaymentLock $paymentLock,
        private MoneiApiFactory $apiFactory,
        private StatusApplier $statusApplier,
        private StateMachineInterface $stateMachine,
        private GatewayConfigurationProvider $gatewayConfigurations,
    ) {
    }

    public function __invoke(NotifyPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestRepository->find($command->hash);
        if (!$paymentRequest instanceof PaymentRequestInterface) {
            throw new PaymentRequestNotFoundException();
        }
        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException('A Sylius core payment is expected.');
        }
        $paymentMethod = $paymentRequest->getMethod();

        // Checked again: `/payment-requests/{hash}` re-dispatches a non-final notify request
        // with any unsigned body merged into its payload.
        [$body, $signature] = self::signedRequest($paymentRequest->getPayload());
        $callback = SignedCallback::verify($body, $signature, $this->gatewayConfigurations->forPaymentMethod($paymentMethod)->apiKey);

        $this->paymentLock->acquire($payment);

        $moneiPayment = $this->apiFactory->forPaymentMethod($paymentMethod)->getPayment($callback->paymentId);
        $this->statusApplier->apply($payment, $moneiPayment);

        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
        }
    }

    /**
     * Body and `MONEI-Signature` header of the HTTP request Sylius stored in the payload.
     *
     * @return array{string, string}
     */
    private static function signedRequest(mixed $payload): array
    {
        $httpRequest = \is_array($payload) ? ($payload['http_request'] ?? null) : null;
        $body = \is_array($httpRequest) ? ($httpRequest['content'] ?? null) : null;
        $headers = \is_array($httpRequest) ? ($httpRequest['headers'] ?? null) : null;
        $signature = \is_array($headers) && \is_array($headers['monei-signature'] ?? null) ? ($headers['monei-signature'][0] ?? null) : null;

        if (!\is_string($body) || !\is_string($signature)) {
            throw InvalidCallbackException::unsigned();
        }

        return [$body, $signature];
    }
}
