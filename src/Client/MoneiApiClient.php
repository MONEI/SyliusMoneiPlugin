<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Client;

use Monei\ApiException;
use Monei\Model\CreatePaymentRequest;
use Monei\Model\PaymentCustomer;
use Monei\Model\PaymentBillingDetails;
use Monei\Model\PaymentShippingDetails;
use Monei\Model\Address;
use Monei\Model\RefundPaymentRequest;
use Monei\MoneiClient;

/**
 * Thin adapter around the official MONEI PHP SDK.
 *
 * Isolates the SDK from Payum's action layer so that:
 * - Actions depend on a mockable interface, not a concrete SDK class.
 * - SDK version upgrades don't ripple through every action.
 * - We can normalise return types to plain arrays for Payum compatibility.
 */
final class MoneiApiClient
{
    private MoneiClient $client;
    private string $apiKey;
    private string $accountId;

    public function __construct(string $apiKey, string $accountId)
    {
        $this->apiKey = $apiKey;
        $this->accountId = $accountId;
        $this->client = new MoneiClient($apiKey);
    }

    /**
     * Create a new MONEI payment.
     *
     * @param array{
     *     amount: int,
     *     currency: string,
     *     orderId: string,
     *     description?: string,
     *     completeUrl: string,
     *     cancelUrl?: string,
     *     callbackUrl: string,
     *     customer?: array{email?: string, name?: string, phone?: string},
     *     billingDetails?: array{name?: string, email?: string, phone?: string, company?: string, address?: array},
     *     shippingDetails?: array{name?: string, email?: string, phone?: string, company?: string, address?: array},
     *     sessionDetails?: array{ip?: string, userAgent?: string},
     * } $params
     *
     * @return array<string, mixed> The created payment as an associative array
     *
     * @throws ApiException
     */
    public function createPayment(array $params): array
    {
        $request = new CreatePaymentRequest([
            'amount' => $params['amount'],
            'currency' => $params['currency'] ?? 'EUR',
            'order_id' => $params['orderId'],
            'description' => $params['description'] ?? '',
            'complete_url' => $params['completeUrl'],
            'cancel_url' => $params['cancelUrl'] ?? null,
            'callback_url' => $params['callbackUrl'],
        ]);

        if (isset($params['customer'])) {
            $request->setCustomer(new PaymentCustomer($params['customer']));
        }

        if (isset($params['billingDetails'])) {
            $billing = $params['billingDetails'];
            $billingRequest = [];
            if (isset($billing['name'])) {
                $billingRequest['name'] = $billing['name'];
            }
            if (isset($billing['email'])) {
                $billingRequest['email'] = $billing['email'];
            }
            if (isset($billing['phone'])) {
                $billingRequest['phone'] = $billing['phone'];
            }
            if (isset($billing['address'])) {
                $billingRequest['address'] = new Address($billing['address']);
            }
            $request->setBillingDetails(new PaymentBillingDetails($billingRequest));
        }

        if (isset($params['shippingDetails'])) {
            $shipping = $params['shippingDetails'];
            $shippingRequest = [];
            if (isset($shipping['name'])) {
                $shippingRequest['name'] = $shipping['name'];
            }
            if (isset($shipping['email'])) {
                $shippingRequest['email'] = $shipping['email'];
            }
            if (isset($shipping['phone'])) {
                $shippingRequest['phone'] = $shipping['phone'];
            }
            if (isset($shipping['address'])) {
                $shippingRequest['address'] = new Address($shipping['address']);
            }
            $request->setShippingDetails(new PaymentShippingDetails($shippingRequest));
        }

        $result = $this->client->payments->create($request);

        return $this->objectToArray($result);
    }

    /**
     * Retrieve a payment by its ID.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function getPayment(string $paymentId): array
    {
        $result = $this->client->payments->getPayment($paymentId);

        return $this->objectToArray($result);
    }

    /**
     * Refund a payment (full or partial).
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function refundPayment(string $paymentId, ?int $amount = null, ?string $reason = null): array
    {
        $params = [];
        if ($amount !== null) {
            $params['amount'] = $amount;
        }
        if ($reason !== null) {
            $params['refund_reason'] = $reason;
        }

        $request = new RefundPaymentRequest($params);
        $result = $this->client->payments->refund($paymentId, $request);

        return $this->objectToArray($result);
    }

    /**
     * Cancel a pending payment.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function cancelPayment(string $paymentId): array
    {
        $result = $this->client->payments->cancel($paymentId);

        return $this->objectToArray($result);
    }

    /**
     * Verify a webhook signature using the SDK's built-in verification.
     *
     * The SDK handles the timestamp-based HMAC signature format that MONEI uses,
     * which includes replay-attack protection.
     *
     * @return object The verified payment object
     *
     * @throws ApiException If the signature is invalid
     */
    public function verifySignature(string $body, string $signature): object
    {
        return $this->client->verifySignature($body, $signature);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    /**
     * Convert an SDK response object to a plain array.
     *
     * Payum stores payment details as arrays, so we normalise SDK objects
     * to keep the action layer framework-agnostic.
     *
     * @return array<string, mixed>
     */
    private function objectToArray(mixed $object): array
    {
        if (is_array($object)) {
            return $object;
        }

        if (method_exists($object, 'jsonSerialize')) {
            return (array) $object->jsonSerialize();
        }

        if (method_exists($object, '__toString')) {
            $json = json_decode((string) $object, true);
            if (is_array($json)) {
                return $json;
            }
        }

        return (array) $object;
    }
}
