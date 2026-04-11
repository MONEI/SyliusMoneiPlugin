<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Action;

use Monei\SyliusPlugin\Client\MoneiApiClient;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use Sylius\Component\Core\Model\PaymentInterface;

final class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    public function __construct()
    {
        $this->apiClass = MoneiApiClient::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        $this->gateway->execute($httpRequest = new GetHttpRequest());

        $body = $httpRequest->content;
        $signature = $httpRequest->headers['monei-signature'][0]
            ?? $httpRequest->headers['MONEI-Signature'][0]
            ?? '';

        /** @var MoneiApiClient $api */
        $api = $this->api;

        // Verify webhook signature using the SDK (handles timestamp-based HMAC)
        if (!empty($signature)) {
            try {
                $api->verifySignature($body, $signature);
            } catch (\Throwable) {
                throw new HttpResponse('Invalid signature', 403);
            }
        }

        $webhookData = json_decode($body, true);

        if (!is_array($webhookData) || !isset($webhookData['id'])) {
            throw new HttpResponse('Invalid payload', 400);
        }

        // Always re-fetch the payment from MONEI to prevent spoofing
        $moneiPayment = $api->getPayment($webhookData['id']);

        $details['monei_payment_id'] = $moneiPayment['id'];
        $details['monei_status'] = $moneiPayment['status'];
        $details['monei_status_message'] = $moneiPayment['statusMessage'] ?? null;
        $details['monei_last_webhook_at'] = (new \DateTimeImmutable())->format('c');

        $payment->setDetails((array) $details);

        throw new HttpResponse('OK', 200);
    }

    public function supports($request): bool
    {
        return $request instanceof Notify
            && $request->getModel() instanceof PaymentInterface;
    }
}
