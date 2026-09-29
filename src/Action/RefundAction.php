<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Action;

use Monei\SyliusPlugin\Client\MoneiApiClientInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Refund;
use Sylius\Component\Core\Model\PaymentInterface;

final class RefundAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = MoneiApiClientInterface::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        if (!isset($details['monei_payment_id'])) {
            throw new \LogicException('Cannot refund: no MONEI payment ID found.');
        }

        /** @var MoneiApiClientInterface $api */
        $api = $this->api;

        $result = $api->refundPayment(
            (string) $details['monei_payment_id'],
            isset($details['amount']) ? (int) $details['amount'] : null,
            'Refund from Sylius admin',
        );

        $details['monei_status'] = $result['status'] ?? 'REFUNDED';
        $details['monei_refund_id'] = $result['id'] ?? null;
        $payment->setDetails((array) $details);
    }

    public function supports($request): bool
    {
        return $request instanceof Refund
            && $request->getModel() instanceof PaymentInterface;
    }
}
