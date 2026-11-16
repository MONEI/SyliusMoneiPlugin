<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Monei\Model\Address;
use Monei\Model\CreatePaymentRequest;
use Monei\Model\PaymentBillingDetails;
use Monei\Model\PaymentCustomer;
use Monei\Model\PaymentSessionDetails;
use Monei\Model\PaymentShippingDetails;
use Monei\Model\PaymentTransactionType;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\MoneiMethod;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Intl\Currencies;

final readonly class CreatePaymentRequestFactory
{
    private const MIN_LIFETIME = 3600;
    private const MAX_LIFETIME = 7 * 86400;
    private const EXPIRY_MARGIN = 3600;

    /** @param string $orderExpirationPeriod `%sylius_order.order_expiration_period%`, for example "5 days" */
    public function __construct(
        private RequestStack $requestStack,
        private ClockInterface $clock,
        private string $orderExpirationPeriod,
    ) {
    }

    /**
     * @param string $returnUrl   where MONEI sends the buyer back (complete, fail and cancel)
     * @param string $callbackUrl where MONEI posts the signed payment
     *
     * @throws \InvalidArgumentException when the payment cannot be sent to MONEI
     */
    public function create(
        PaymentInterface $payment,
        GatewayConfiguration $configuration,
        string $returnUrl,
        string $callbackUrl,
    ): CreatePaymentRequest {
        $order = $payment->getOrder();
        if (!$order instanceof OrderInterface) {
            throw new \InvalidArgumentException('The payment has no order.');
        }

        $currency = strtoupper((string) $payment->getCurrencyCode());
        // Sylius stores every amount with 2 decimals. For a currency with 0 or 3 decimals
        // MONEI would read the same integer as 100 times or a tenth of the price.
        if (!Currencies::exists($currency) || 2 !== Currencies::getFractionDigits($currency)) {
            throw new \InvalidArgumentException(\sprintf('MONEI payments in Sylius support only currencies with 2 decimals, got "%s".', $currency));
        }

        $amount = (int) $payment->getAmount();
        if ($amount <= 0) {
            throw new \InvalidArgumentException(\sprintf('The payment amount must be greater than 0, got %d.', $amount));
        }

        $request = new CreatePaymentRequest([
            'amount' => $amount,
            'currency' => $currency,
            'order_id' => $this->orderId($payment, $order, $configuration),
            'description' => $this->description($order),
            'complete_url' => $returnUrl,
            'fail_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'callback_url' => $callbackUrl,
            'allowed_payment_methods' => $configuration->method->allowedPaymentMethods(),
            'transaction_type' => $configuration->authorize ? PaymentTransactionType::AUTH : PaymentTransactionType::SALE,
            'expire_at' => $this->expireAt($order),
        ]);

        $customer = $order->getCustomer();
        $email = $customer?->getEmail();
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        $request->setCustomer(new PaymentCustomer(array_filter([
            'email' => $email,
            'name' => $billing?->getFullName() ?: $customer?->getFullName(),
            'phone' => $billing?->getPhoneNumber() ?: $customer?->getPhoneNumber(),
        ])));

        if (null !== $billing) {
            $request->setBillingDetails(new PaymentBillingDetails($this->addressDetails($billing, $email)));
        }
        if (null !== $shipping) {
            $request->setShippingDetails(new PaymentShippingDetails($this->addressDetails($shipping, $email)));
        }

        $httpRequest = $this->requestStack->getMainRequest();
        if (null !== $httpRequest) {
            $request->setSessionDetails(new PaymentSessionDetails(array_filter([
                'ip' => $httpRequest->getClientIp(),
                'user_agent' => $httpRequest->headers->get('User-Agent'),
                'lang' => $order->getLocaleCode() ? substr((string) $order->getLocaleCode(), 0, 2) : null,
            ])));
        }

        return $request;
    }

    private function orderId(PaymentInterface $payment, OrderInterface $order, GatewayConfiguration $configuration): string
    {
        $orderId = $configuration->orderIdPrefix.(string) $order->getNumber();

        // The hosted page of these methods hands the payment to the processor at once, until it
        // expires. MONEI's duplicate check would then refuse every other payment of the order.
        if (\in_array($configuration->method, [MoneiMethod::Multibanco, MoneiMethod::MbWay, MoneiMethod::PayPal], true)) {
            $paymentId = $payment->getId();
            if (!\is_int($paymentId) && !\is_string($paymentId)) {
                throw new \InvalidArgumentException('The payment has no id.');
            }
            $orderId .= '-'.$paymentId;
        }

        if (1 !== preg_match('/^[a-zA-Z0-9_@.\/#&+-]{1,40}$/D', $orderId)) {
            throw new \InvalidArgumentException(\sprintf('MONEI refuses the order ID "%s": use at most 40 letters, digits and _@./#&+-.', $orderId));
        }

        return $orderId;
    }

    /**
     * One hour before the unpaid-orders command may cancel the order, so MONEI expires the
     * payment first. MONEI refuses a past expireAt, and expires a PENDING payment after 7 days at most.
     */
    private function expireAt(OrderInterface $order): int
    {
        $now = $this->clock->now();
        $expireAt = \DateTimeImmutable::createFromInterface($order->getCheckoutCompletedAt() ?? $now)
            ->modify('+'.$this->orderExpirationPeriod)
            ->getTimestamp() - self::EXPIRY_MARGIN;

        return max($now->getTimestamp() + self::MIN_LIFETIME, min($now->getTimestamp() + self::MAX_LIFETIME, $expireAt));
    }

    private function description(OrderInterface $order): string
    {
        $channelName = $order->getChannel()?->getName();

        return null !== $channelName && '' !== $channelName
            ? \sprintf('%s - #%s', $channelName, (string) $order->getNumber())
            : \sprintf('#%s', (string) $order->getNumber());
    }

    /** @return array<string, mixed> */
    private function addressDetails(AddressInterface $address, ?string $email): array
    {
        return array_filter([
            'name' => $address->getFullName() ?: null,
            'email' => $email,
            'phone' => $address->getPhoneNumber(),
            'company' => $address->getCompany(),
            'address' => new Address(array_filter([
                'line1' => $address->getStreet(),
                'city' => $address->getCity(),
                'state' => $address->getProvinceName() ?? $address->getProvinceCode(),
                'zip' => $address->getPostcode(),
                'country' => null !== $address->getCountryCode() ? strtoupper($address->getCountryCode()) : null,
            ])),
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }
}
