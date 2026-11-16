<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\CommandHandler;

use Monei\ApiException;
use Monei\Model\CreatePaymentRequest;
use Monei\Model\Payment as MoneiPayment;
use Monei\Model\PaymentNextAction;
use Monei\Model\PaymentStatus;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\Callback\MoneiNotifyPaymentProvider;
use Monei\SyliusPlugin\Command\CapturePaymentRequest;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Payment\BoundPayment;
use Monei\SyliusPlugin\Payment\BoundPaymentAccess;
use Monei\SyliusPlugin\Payment\CreatePaymentRequestFactory;
use Monei\SyliusPlugin\Payment\MoneiDetails;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Monei\SyliusPlugin\Payment\UnreachableBoundPaymentException;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Exception\PaymentRequestNotFoundException;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webmozart\Assert\Assert;

/**
 * Runs on every visit of `/pay/{hash}` for a non-final capture or authorize request:
 * creates the MONEI payment, or reuses the PENDING one, and stores its redirect URL.
 * An expected failure returns normally: a throw would roll back the failed state.
 */
final readonly class CapturePaymentRequestHandler
{
    private const RETRYABLE = [PaymentStatus::FAILED, PaymentStatus::CANCELED, PaymentStatus::EXPIRED];

    private const PENDING = [PaymentStatus::PENDING, PaymentStatus::PENDING_PROCESSING];

    /** MONEI's duplicate check: another payment of this order is with the processor, authorized or paid. */
    private const DUPLICATE_ORDER = '/^The order ".*" (is pending processing|has already been authorized|has already been paid)$/s';

    private const IN_PROGRESS_FLASH = 'monei.payment.in_progress';

    /** @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository */
    public function __construct(
        private PaymentRequestRepositoryInterface $paymentRequestRepository,
        private PaymentLock $paymentLock,
        private BoundPaymentAccess $boundPayments,
        private MoneiApiFactory $apiFactory,
        private CreatePaymentRequestFactory $createPaymentRequestFactory,
        private StatusApplier $statusApplier,
        private StateMachineInterface $stateMachine,
        private UrlProviderInterface $afterPayUrlProvider,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CapturePaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestRepository->find($command->hash);
        if (!$paymentRequest instanceof PaymentRequestInterface) {
            throw new PaymentRequestNotFoundException();
        }
        $payment = $paymentRequest->getPayment();
        Assert::isInstanceOf($payment, PaymentInterface::class);

        $this->paymentLock->acquire($payment);

        // A stale `/pay/{hash}`: the payment was paid, failed or cancelled meanwhile, or the buyer changed its method.
        if (PaymentInterface::STATE_NEW !== $payment->getState() || $paymentRequest->getMethod()->getId() !== $payment->getMethod()?->getId()) {
            $this->complete($paymentRequest);

            return;
        }

        try {
            $bound = $this->boundPayments->fetch($payment);
        } catch (UnreachableBoundPaymentException) {
            $bound = null;
        } catch (ApiException $e) {
            $this->logger->error('Fetching MONEI payment {monei_payment} of Sylius payment {payment} failed ({code}): the buyer can try again.', [
                'monei_payment' => MoneiDetails::paymentId($payment),
                'payment' => $payment->getId(),
                'code' => $e->getCode(),
            ]);
            $this->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

            return;
        }

        $this->handle($paymentRequest, $payment, $bound);
    }

    private function handle(PaymentRequestInterface $paymentRequest, PaymentInterface $payment, ?BoundPayment $bound): void
    {
        if (null === $bound || \in_array(self::status($bound->payment), self::RETRYABLE, true)) {
            $this->create($paymentRequest, $payment);

            return;
        }

        if (!\in_array(self::status($bound->payment), self::PENDING, true)) {
            $this->statusApplier->apply($payment, $bound->payment);
            $this->complete($paymentRequest);

            return;
        }

        if (MoneiDetails::paymentMethodCode($payment) === $paymentRequest->getMethod()->getCode()
            && $bound->payment->getAmount() === $payment->getAmount()) {
            $this->forward($paymentRequest, $bound->payment);

            return;
        }

        $this->replace($paymentRequest, $payment, $bound);
    }

    /** The bound PENDING payment was made for another method or amount. */
    private function replace(PaymentRequestInterface $paymentRequest, PaymentInterface $payment, BoundPayment $bound): void
    {
        $moneiPaymentId = (string) $bound->payment->getId();
        $context = ['monei_payment' => $moneiPaymentId, 'payment' => $payment->getId()];

        try {
            $bound->api->cancelPayment($moneiPaymentId);
            $this->create($paymentRequest, $payment);

            return;
        } catch (ApiException $e) {
            $context['code'] = $e->getCode();
        }

        try {
            $bound = new BoundPayment($bound->api->getPayment($moneiPaymentId), $bound->api);
        } catch (ApiException $e) {
            $this->logger->error('MONEI refused to cancel payment {monei_payment} of Sylius payment {payment} ({code}), and fetching it again failed: the buyer can try again.', $context);
            $this->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);

            return;
        }

        if (!\in_array(self::status($bound->payment), self::PENDING, true)) {
            $this->handle($paymentRequest, $payment, $bound);

            return;
        }

        // Still PENDING: it is with the processor.
        $createRequest = $this->createRequest($paymentRequest, $payment);
        if (null === $createRequest) {
            return;
        }

        if ($createRequest->getOrderId() === $bound->payment->getOrderId()) {
            // MONEI's duplicate check would refuse a second payment of this order anyway.
            $this->logger->warning('MONEI payment {monei_payment} of Sylius payment {payment} is with the processor and cannot be cancelled ({code}): no new payment until it ends.', $context);
            $this->flashInProgress();
            $this->complete($paymentRequest);

            return;
        }

        $this->logger->warning('MONEI payment {monei_payment} of Sylius payment {payment} is with the processor and cannot be cancelled ({code}): a new payment is created, and the old one stays payable until it expires.', $context);
        $this->send($paymentRequest, $payment, $createRequest);
    }

    private function create(PaymentRequestInterface $paymentRequest, PaymentInterface $payment): void
    {
        $createRequest = $this->createRequest($paymentRequest, $payment);
        if (null !== $createRequest) {
            $this->send($paymentRequest, $payment, $createRequest);
        }
    }

    /** @return CreatePaymentRequest|null null when the payment cannot be sent; the payment is then failed */
    private function createRequest(PaymentRequestInterface $paymentRequest, PaymentInterface $payment): ?CreatePaymentRequest
    {
        $paymentMethod = $paymentRequest->getMethod();

        try {
            return $this->createPaymentRequestFactory->create(
                $payment,
                GatewayConfiguration::fromPaymentMethod($paymentMethod),
                $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL),
                MoneiNotifyPaymentProvider::callbackUrl($this->urlGenerator, $payment, $paymentMethod),
            );
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('Sylius payment {payment} cannot be sent to MONEI: {reason}', ['payment' => $payment->getId(), 'reason' => $e->getMessage()]);
            $this->failPayment($paymentRequest, $payment);

            return null;
        }
    }

    private function send(PaymentRequestInterface $paymentRequest, PaymentInterface $payment, CreatePaymentRequest $createRequest): void
    {
        $paymentMethod = $paymentRequest->getMethod();
        $context = ['payment' => $payment->getId(), 'order_id' => $createRequest->getOrderId()];

        try {
            $moneiPayment = $this->apiFactory->forPaymentMethod($paymentMethod)->createPayment($createRequest);
        } catch (ApiException $e) {
            $context += ['code' => $e->getCode(), 'message' => self::errorMessage($e)];
            if (400 === $e->getCode() && 1 === preg_match(self::DUPLICATE_ORDER, $context['message'])) {
                // Failing the payment would make Sylius create payment after payment for this order.
                $this->logger->error('MONEI refused a new payment for Sylius payment {payment} (order ID {order_id}): {message}', $context);
                $this->complete($paymentRequest);

                return;
            }

            // After a 5xx or a timeout the MONEI payment may exist, but it is not bound and the buyer never sees it.
            $this->logger->error('MONEI did not create a payment for Sylius payment {payment} (order ID {order_id}): {code} {message}', $context);
            $this->failPayment($paymentRequest, $payment);

            return;
        }

        MoneiDetails::bind($payment, (string) $moneiPayment->getId(), (string) $paymentMethod->getCode());

        if (PaymentStatus::FAILED === self::status($moneiPayment)) {
            $this->logger->error('MONEI created payment {monei_payment} for Sylius payment {payment} as FAILED: {message}', $context + [
                'monei_payment' => $moneiPayment->getId(),
                'message' => $moneiPayment->getStatusMessage(),
            ]);
            $this->failPayment($paymentRequest, $payment);

            return;
        }

        $this->forward($paymentRequest, $moneiPayment);
    }

    /** Sends the buyer to the PENDING MONEI payment: its next action resumes an abandoned 3DS at its challenge. */
    private function forward(PaymentRequestInterface $paymentRequest, MoneiPayment $moneiPayment): void
    {
        $nextAction = $moneiPayment->getNextAction();
        if (PaymentNextAction::TYPE_COMPLETE === $nextAction?->getType()) {
            $this->flashInProgress();
            $this->complete($paymentRequest);

            return;
        }

        $paymentRequest->setResponseData(['redirect_url' => (string) $nextAction?->getRedirectUrl()]);
        // A processing request keeps the payment amount against cart changes (Sylius 2.2.9+).
        if (PaymentRequestInterface::STATE_NEW === $paymentRequest->getState()) {
            $this->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_PROCESS);
        }
    }

    private function failPayment(PaymentRequestInterface $paymentRequest, PaymentInterface $payment): void
    {
        $this->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL);
        $this->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
    }

    private function complete(PaymentRequestInterface $paymentRequest): void
    {
        $this->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function apply(object $subject, string $graph, string $transition): void
    {
        if ($this->stateMachine->can($subject, $graph, $transition)) {
            $this->stateMachine->apply($subject, $graph, $transition);
        }
    }

    private function flashInProgress(): void
    {
        $request = $this->requestStack->getMainRequest();
        $session = null !== $request && $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('info', self::IN_PROGRESS_FLASH);
        }
    }

    private static function status(MoneiPayment $moneiPayment): string
    {
        /** @var string|null $status the SDK documents a PaymentStatus object but returns the enum's string value */
        $status = $moneiPayment->getStatus();

        return (string) $status;
    }

    /** MONEI error bodies are `{status, statusCode, message, requestId}`. */
    private static function errorMessage(ApiException $e): string
    {
        $body = $e->getResponseBody();
        $decoded = \is_string($body) ? json_decode($body, true) : null;

        return \is_array($decoded) && \is_string($decoded['message'] ?? null) ? $decoded['message'] : '';
    }
}
