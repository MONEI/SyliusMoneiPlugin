<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Client;

use Monei\Model\Address;
use Monei\Model\CreatePaymentRequest;
use Monei\Model\PaymentBillingDetails;
use Monei\Model\PaymentCustomer;
use Monei\Model\PaymentShippingDetails;
use Monei\Model\RefundPaymentRequest;
use Monei\MoneiClient;

/**
 * Thin adapter around the official MONEI PHP SDK.
 *
 * Isolates the SDK from Payum's action layer so that:
 * - Actions depend on MoneiApiClientInterface, not a concrete SDK class.
 * - SDK version upgrades don't ripple through every action.
 * - We can normalise return types to plain arrays for Payum compatibility.
 */
final class MoneiApiClient implements MoneiApiClientInterface
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

    public function createPayment(array $params): array
    {
        $request = new CreatePaymentRequest([
            'amount' => $params['amount'],
            'currency' => $params['currency'],
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

    public function getPayment(string $paymentId): array
    {
        $result = $this->client->payments->get($paymentId);

        return $this->objectToArray($result);
    }

    public function refundPayment(string $paymentId, ?int $amount = null, ?string $reason = null): array
    {
        $params = [];
        if (null !== $amount) {
            $params['amount'] = $amount;
        }
        if (null !== $reason) {
            $params['refund_reason'] = $reason;
        }

        $request = new RefundPaymentRequest($params);
        $result = $this->client->payments->refund($paymentId, $request);

        return $this->objectToArray($result);
    }

    public function cancelPayment(string $paymentId): array
    {
        $result = $this->client->payments->cancel($paymentId);

        return $this->objectToArray($result);
    }

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

        // jsonSerialize() returns nested models as stdClass; a JSON round-trip
        // turns them into arrays too, so callers can read e.g. nextAction.redirectUrl.
        if ($object instanceof \JsonSerializable) {
            $json = json_decode(json_encode($object, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($json)) {
                return $json;
            }
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
