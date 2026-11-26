<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\CommandHandler;

use Monei\ApiException;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\Api\UnreadableAnswerException;
use Monei\SyliusPlugin\Callback\CallbackQuota;
use Monei\SyliusPlugin\Callback\CallbackVerifier;
use Monei\SyliusPlugin\Callback\InvalidCallbackException;
use Monei\SyliusPlugin\Command\NotifyPaymentRequest;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Exception\PaymentRequestNotFoundException;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;

/** Fetches the callback's payment under the route method's credentials before applying any status. */
final readonly class NotifyPaymentRequestHandler
{
    /** @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository */
    public function __construct(
        private PaymentRequestRepositoryInterface $paymentRequestRepository,
        private PaymentLock $paymentLock,
        private MoneiApiFactory $apiFactory,
        private StatusApplier $statusApplier,
        private StateMachineInterface $stateMachine,
        private CallbackVerifier $verifier,
        private CallbackQuota $quota,
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
        [$body, $signature] = self::storedRequest($paymentRequest->getPayload());
        $callback = $this->verifier->forPaymentMethod($body, $signature, $paymentMethod);
        $paymentId = (int) $payment->getId();
        if (null === $callback->apiKey) {
            $this->quota->check($paymentId, $callback->paymentId);
        }
        $apiKey = $callback->apiKeyOrFail();

        $this->paymentLock->acquire($payment);

        $orderId = null;
        if (!$callback->signed) {
            $orderId = MoneiDetails::orderId($payment);
            if (null === $orderId && $callback->paymentId !== MoneiDetails::paymentId($payment)) {
                throw InvalidCallbackException::acknowledged($callback->paymentId, 'the callback payment id differs from the binding and the Sylius payment has no stored order id');
            }

            $this->quota->charge($paymentId, $callback->paymentId);
        }

        try {
            $moneiPayment = $this->apiFactory->forApiKey($apiKey)->getPayment($callback->paymentId);
        } catch (ApiException $e) {
            if (!$callback->signed && ($e instanceof UnreadableAnswerException || 404 === $e->getCode())) {
                throw InvalidCallbackException::acknowledged($callback->paymentId, 'the requested payment was not found or could not be read');
            }

            throw $e;
        }
        if (null !== $orderId && $orderId !== $moneiPayment->getOrderId()) {
            throw InvalidCallbackException::acknowledged($callback->paymentId, 'the fetched order id differs from the bound order id');
        }

        $this->statusApplier->apply($payment, $moneiPayment);

        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
        }
    }

    /**
     * Body and `MONEI-Signature` header of the HTTP request Sylius stored in the payload.
     *
     * @return array{string, ?string}
     */
    private static function storedRequest(mixed $payload): array
    {
        $httpRequest = \is_array($payload) ? ($payload['http_request'] ?? null) : null;
        $body = \is_array($httpRequest) ? ($httpRequest['content'] ?? null) : null;
        $headers = \is_array($httpRequest) ? ($httpRequest['headers'] ?? null) : null;
        $signature = \is_array($headers) && \is_array($headers['monei-signature'] ?? null) ? ($headers['monei-signature'][0] ?? null) : null;

        if (!\is_string($body)) {
            throw InvalidCallbackException::unsigned();
        }

        return [$body, \is_string($signature) ? $signature : null];
    }
}
