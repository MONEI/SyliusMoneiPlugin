<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Api;

use Monei\Api\ApplePayDomainApi;
use Monei\Api\PaymentsApi;
use Monei\ApiException;
use Monei\Model\CancelPaymentRequest;
use Monei\Model\CapturePaymentRequest;
use Monei\Model\CreatePaymentRequest;
use Monei\Model\Payment;
use Monei\Model\PaymentCancellationReason;
use Monei\Model\PaymentRefundReason;
use Monei\Model\PaymentStatus;
use Monei\Model\RefundPaymentRequest;
use Monei\Model\RegisterApplePayDomainRequest;

/** The MONEI API calls of one account (API key). Every call throws ApiException on an error answer. */
final readonly class MoneiApi
{
    public const PENDING = [PaymentStatus::PENDING, PaymentStatus::PENDING_PROCESSING];

    /** Paid, and not fully refunded. */
    public const REFUNDABLE = [PaymentStatus::SUCCEEDED, PaymentStatus::PARTIALLY_REFUNDED];

    /** The code of the ApiException for a payment answer the SDK cannot read. */
    private const UNREADABLE_ANSWER = 502;

    public function __construct(
        private PaymentsApi $payments,
        private ApplePayDomainApi $applePayDomain,
    ) {
    }

    /** @throws ApiException */
    public function createPayment(CreatePaymentRequest $request): Payment
    {
        return self::payment(fn (): object => $this->payments->create($request));
    }

    /** @throws ApiException */
    public function getPayment(string $id): Payment
    {
        return self::payment(fn (): object => $this->payments->get($id));
    }

    /**
     * Captures the full authorized amount.
     *
     * @throws ApiException
     */
    public function capturePayment(string $id): Payment
    {
        return self::payment(fn (): object => $this->payments->capture($id, new CapturePaymentRequest()));
    }

    /** @throws ApiException */
    public function cancelPayment(string $id): Payment
    {
        return self::payment(fn (): object => $this->payments->cancel($id, new CancelPaymentRequest([
            'cancellation_reason' => PaymentCancellationReason::REQUESTED_BY_CUSTOMER,
        ])));
    }

    /**
     * @param int $amount minor units
     *
     * @throws ApiException
     */
    public function refundPayment(string $id, int $amount): Payment
    {
        return self::payment(fn (): object => $this->payments->refund($id, new RefundPaymentRequest([
            'amount' => $amount,
            'refund_reason' => PaymentRefundReason::REQUESTED_BY_CUSTOMER,
        ])));
    }

    /** @throws ApiException */
    public function registerApplePayDomain(string $domain): void
    {
        $this->applePayDomain->register(new RegisterApplePayDomainRequest(['domain_name' => $domain]));
    }

    /** The SDK documents a PaymentStatus object but returns the enum's string value. */
    public static function status(Payment $payment): string
    {
        /** @var string|null $status */
        $status = $payment->getStatus();

        return (string) $status;
    }

    /** MONEI error bodies are `{status, statusCode, message, requestId}`. */
    public static function errorMessage(ApiException $e): string
    {
        $body = $e->getResponseBody();
        $decoded = \is_string($body) ? json_decode($body, true) : null;

        return \is_array($decoded) && \is_string($decoded['message'] ?? null) ? $decoded['message'] : '';
    }

    /** MONEI's message, else what went wrong: the SDK gives code 0 for a connect error or a timeout. */
    public static function reason(ApiException $e): string
    {
        $message = self::errorMessage($e);

        return match (true) {
            '' !== $message => $message,
            0 === $e->getCode() => 'network error',
            default => 'HTTP '.$e->getCode(),
        };
    }

    /**
     * The SDK returns an error model instead of throwing only for a client without the `http_errors` middleware.
     *
     * @param callable(): object $call
     *
     * @throws ApiException
     */
    private static function payment(callable $call): Payment
    {
        try {
            $result = $call();
        } catch (\InvalidArgumentException $e) {
            // The SDK throws this, not an ApiException, for a status outside its enum.
            throw new ApiException('MONEI answered a payment the SDK cannot read: '.$e->getMessage(), self::UNREADABLE_ANSWER);
        }
        if (!$result instanceof Payment) {
            throw new \UnexpectedValueException(\sprintf('MONEI answered %s instead of a payment.', $result::class));
        }

        return $result;
    }
}
