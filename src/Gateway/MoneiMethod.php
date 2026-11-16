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

    /** MB WAY and Multibanco have no monei.js component. */
    public function supportsOnsite(): bool
    {
        return match ($this) {
            self::MbWay, self::Multibanco => false,
            default => true,
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
