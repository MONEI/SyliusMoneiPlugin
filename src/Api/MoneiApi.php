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
use Monei\Model\RefundPaymentRequest;
use Monei\Model\RegisterApplePayDomainRequest;

/** The MONEI API calls of one account (API key). Every call throws ApiException on an error answer. */
final readonly class MoneiApi
{
    public function __construct(
        private PaymentsApi $payments,
        private ApplePayDomainApi $applePayDomain,
    ) {
    }

    /** @throws ApiException */
    public function createPayment(CreatePaymentRequest $request): Payment
    {
        return $this->payments->create($request);
    }

    /** @throws ApiException */
    public function getPayment(string $id): Payment
    {
        return $this->payments->get($id);
    }

    /** Captures the full authorized amount. @throws ApiException */
    public function capturePayment(string $id): Payment
    {
        return $this->payments->capture($id, new CapturePaymentRequest());
    }

    /** @throws ApiException */
    public function cancelPayment(string $id): Payment
    {
        return $this->payments->cancel($id, new CancelPaymentRequest([
            'cancellation_reason' => PaymentCancellationReason::REQUESTED_BY_CUSTOMER,
        ]));
    }

    /** @param int $amount minor units @throws ApiException */
    public function refundPayment(string $id, int $amount): Payment
    {
        return $this->payments->refund($id, new RefundPaymentRequest([
            'amount' => $amount,
            'refund_reason' => PaymentRefundReason::REQUESTED_BY_CUSTOMER,
        ]));
    }

    /** @throws ApiException */
    public function registerApplePayDomain(string $domain): void
    {
        $this->applePayDomain->register(new RegisterApplePayDomainRequest(['domain_name' => $domain]));
    }
}
