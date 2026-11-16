<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Gateway;

/** The MONEI payment method that one Sylius payment method offers. */
enum MoneiMethod: string
{
    case Card = 'card';
    case Bizum = 'bizum';
    case PayPal = 'paypal';
    case AppleGooglePay = 'apple_google_pay';
    case MbWay = 'mbway';
    case Multibanco = 'multibanco';

    /** @return list<string> MONEI `allowedPaymentMethods` values */
    public function allowedPaymentMethods(): array
    {
        return match ($this) {
            self::Card => ['card'],
            self::Bizum => ['bizum'],
            self::PayPal => ['paypal'],
            self::AppleGooglePay => ['applePay', 'googlePay'],
            self::MbWay => ['mbway'],
            self::Multibanco => ['multibanco'],
        };
    }

    public function supportsOnsite(): bool
    {
        return null !== $this->onsiteComponent();
    }

    /** The monei.js component of the onsite page; null for MB WAY and Multibanco, which have none. */
    public function onsiteComponent(): ?string
    {
        return match ($this) {
            self::Card => 'card',
            self::Bizum => 'bizum',
            self::PayPal => 'paypal',
            self::AppleGooglePay => 'payment_request',
            self::MbWay, self::Multibanco => null,
        };
    }

    /** MONEI authorizes (AUTH) only cards, wallets that tokenize a card, and PayPal. */
    public function supportsAuthorize(): bool
    {
        return match ($this) {
            self::Card, self::AppleGooglePay, self::PayPal => true,
            default => false,
        };
    }
}
