<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Twig;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\GatewayConfigurationProvider;
use Monei\SyliusPlugin\Gateway\InvalidGatewayConfigurationException;
use Monei\SyliusPlugin\Gateway\MoneiMethod;
use Monei\SyliusPlugin\Payment\CreatePaymentRequestFactory;
use Monei\SyliusPlugin\Payment\SessionId;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigTest;

/** Scalars only: the gateway configuration holds the API key. */
final class MoneiExtension extends AbstractExtension
{
    public function __construct(
        private readonly SessionId $sessionId,
        private readonly CardBrandLogos $cardBrandLogos,
        private readonly GatewayConfigurationProvider $gatewayConfigurations,
    ) {
    }

    private const MONEI_JS_URL = 'https://js.monei.com/v3/monei.js';

    public function getFunctions(): array
    {
        return [
            new TwigFunction('monei_method_logos', $this->methodLogos(...)),
            new TwigFunction('monei_inline_checkout', $this->inlineCheckout(...)),
            new TwigFunction('monei_js_url', static fn (): string => self::MONEI_JS_URL),
            new TwigFunction('monei_inline_entry_prefix', static fn (): string => SessionId::ENTRY_KEY_PREFIX),
        ];
    }

    public function getTests(): array
    {
        return [
            new TwigTest('monei_payment_method', GatewayConfiguration::isMonei(...)),
        ];
    }

    /**
     * Null for a non-MONEI method and for a broken config: the Payment step and the admin
     * list render every method, so one bad config must not break the page.
     *
     * @return array{method: string, logos: list<string>, wordmarks: bool, test_mode: bool}|null
     */
    public function methodLogos(PaymentMethodInterface $paymentMethod): ?array
    {
        if (!GatewayConfiguration::isMonei($paymentMethod)) {
            return null;
        }

        try {
            $config = $this->gatewayConfigurations->forPaymentMethod($paymentMethod);
        } catch (InvalidGatewayConfigurationException) {
            return null;
        }

        $card = MoneiMethod::Card === $config->method;

        return [
            'method' => $config->method->value,
            'logos' => $card ? $this->cardBrandLogos->forApiKey($config->apiKey) : $config->method->logos(),
            'wordmarks' => !$card,
            'test_mode' => $config->testMode(),
        ];
    }

    /**
     * The monei.js component props for the checkout Complete page; null unless the cart pays
     * with an inline MONEI method. The page renders for every checkout, so a broken config
     * gives null, as for the logos.
     *
     * @return array{component: string, account_id: string, session_id: string, entry_key: string, amount: int, currency: string, language: ?string, card_layout: string, transaction_type: string, method_code: string}|null
     */
    public function inlineCheckout(OrderInterface $order): ?array
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
        $paymentMethod = $payment?->getMethod();
        if (null === $payment || null === $paymentMethod || null === $order->getId() || null === $payment->getId() || !GatewayConfiguration::isMonei($paymentMethod)) {
            return null;
        }

        try {
            $config = $this->gatewayConfigurations->forPaymentMethod($paymentMethod);
        } catch (InvalidGatewayConfigurationException) {
            return null;
        }
        $component = $config->method->onsiteComponent();
        if (!$config->inline() || null === $component || null === $config->accountId) {
            return null;
        }
        // The pay page cannot create these payments, and the buyer would approve a token for nothing.
        if (!CreatePaymentRequestFactory::supportsPayment($payment)) {
            return null;
        }

        return [
            'component' => $component,
            'account_id' => $config->accountId,
            'session_id' => $this->sessionId->forOrder($order),
            'entry_key' => $this->sessionId->entryKey($payment),
            // Button components only: MONEI refuses their token when the amount or currency differs from the payment's.
            'amount' => (int) $payment->getAmount(),
            'currency' => strtoupper((string) $payment->getCurrencyCode()),
            'language' => CreatePaymentRequestFactory::language($order),
            'card_layout' => $config->cardLayout,
            'transaction_type' => $config->transactionType(),
            'method_code' => (string) $paymentMethod->getCode(),
        ];
    }
}
