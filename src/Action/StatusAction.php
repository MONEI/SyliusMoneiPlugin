<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Action;

use Monei\SyliusPlugin\Client\MoneiApiClient;
use Monei\SyliusPlugin\Resolver\PaymentStatusResolver;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class StatusAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    private PaymentStatusResolver $statusResolver;

    public function __construct(?PaymentStatusResolver $statusResolver = null)
    {
        $this->apiClass = MoneiApiClient::class;
        $this->statusResolver = $statusResolver ?? new PaymentStatusResolver();
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        if (!isset($details['monei_payment_id'])) {
            $request->markNew();

            return;
        }

        /** @var MoneiApiClient $api */
        $api = $this->api;

        try {
            $moneiPayment = $api->getPayment((string) $details['monei_payment_id']);
            $status = $moneiPayment['status'] ?? 'PENDING';

            $details['monei_status'] = $status;
            $details['monei_status_message'] = $moneiPayment['statusMessage'] ?? null;
            $payment->setDetails((array) $details);
        } catch (\Throwable) {
            // If we can't reach MONEI, fall back to the cached status
            $status = $details['monei_status'] ?? 'PENDING';
        }

        $this->statusResolver->resolve($status, $request);
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface
            && $request->getModel() instanceof PaymentInterface;
    }
}
