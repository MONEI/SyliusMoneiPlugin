<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Convert;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class ConvertPaymentAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();

        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        $customer = $order->getCustomer();
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        $details['amount'] = $payment->getAmount();
        $details['currency'] = $payment->getCurrencyCode();
        $details['orderId'] = (string) $order->getNumber();
        $details['description'] = sprintf('Order #%s', $order->getNumber());

        if (null !== $customer) {
            $details['customer'] = array_filter([
                'email' => $customer->getEmail(),
                'name' => $customer->getFullName(),
                'phone' => $customer->getPhoneNumber(),
            ]);
        }

        if (null !== $billingAddress) {
            $details['billingDetails'] = array_filter([
                'name' => trim(sprintf('%s %s', $billingAddress->getFirstName() ?? '', $billingAddress->getLastName() ?? '')),
                'email' => $customer?->getEmail(),
                'phone' => $billingAddress->getPhoneNumber(),
                'company' => $billingAddress->getCompany(),
                'address' => array_filter([
                    'line1' => $billingAddress->getStreet(),
                    'city' => $billingAddress->getCity(),
                    'state' => $billingAddress->getProvinceName(),
                    'zip' => $billingAddress->getPostcode(),
                    'country' => $billingAddress->getCountryCode(),
                ]),
            ]);
        }

        if (null !== $shippingAddress) {
            $details['shippingDetails'] = array_filter([
                'name' => trim(sprintf('%s %s', $shippingAddress->getFirstName() ?? '', $shippingAddress->getLastName() ?? '')),
                'email' => $customer?->getEmail(),
                'phone' => $shippingAddress->getPhoneNumber(),
                'company' => $shippingAddress->getCompany(),
                'address' => array_filter([
                    'line1' => $shippingAddress->getStreet(),
                    'city' => $shippingAddress->getCity(),
                    'state' => $shippingAddress->getProvinceName(),
                    'zip' => $shippingAddress->getPostcode(),
                    'country' => $shippingAddress->getCountryCode(),
                ]),
            ]);
        }

        $request->setResult((array) $details);
    }

    public function supports($request): bool
    {
        return $request instanceof Convert
            && $request->getSource() instanceof PaymentInterface
            && 'array' === $request->getTo();
    }
}
