<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Callback;

use Monei\ApiException;
use Monei\Configuration;
use Monei\MoneiClient;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Monei\SyliusPlugin\Gateway\GatewayConfigurationProvider;
use Monei\SyliusPlugin\Gateway\InvalidGatewayConfigurationException;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final readonly class CallbackVerifier
{
    public function __construct(private GatewayConfigurationProvider $gatewayConfigurations)
    {
    }

    public function forPaymentMethod(string $body, ?string $header, PaymentMethodInterface $method): VerifiedCallback
    {
        try {
            $configuration = $this->gatewayConfigurations->forPaymentMethod($method);
        } catch (InvalidGatewayConfigurationException) {
            $configuration = null;
        }

        return self::verify($body, $header, $configuration);
    }

    public static function verify(string $body, ?string $header, ?GatewayConfiguration $configuration): VerifiedCallback
    {
        if (GatewayConfiguration::MANUAL === $configuration?->credentials) {
            $callback = SignedCallback::verify($body, $header, $configuration->apiKey);

            return new VerifiedCallback($callback->paymentId, true, $configuration->apiKey);
        }

        if (null !== $configuration && self::matchesV2($body, $header, $configuration)) {
            return new VerifiedCallback(SignedCallback::paymentIdFromBody($body), true, $configuration->apiKey);
        }

        if (\strlen($body) > 16384 || (null !== $header && !self::headerParses($header))) {
            throw InvalidCallbackException::refused('invalid unsigned callback body size or signature header');
        }

        $paymentId = SignedCallback::paymentIdFromBody($body);
        if (1 !== preg_match('/^[a-f0-9]{32,40}\z/i', $paymentId)) {
            throw InvalidCallbackException::refused('invalid MONEI payment id');
        }

        return new VerifiedCallback($paymentId, false, $configuration?->apiKey);
    }

    private static function matchesV2(string $body, ?string $header, GatewayConfiguration $configuration): bool
    {
        if (null === $header || null === $configuration->signingSecret || '' === $configuration->signingSecret) {
            return false;
        }

        try {
            (new MoneiClient($configuration->apiKey, new Configuration()))->verifySignature($body, $header, $configuration->signingSecret);

            return true;
        } catch (ApiException) {
            return false;
        }
    }

    private static function headerParses(string $header): bool
    {
        $hasTimestamp = false;
        foreach (explode(',', $header) as $element) {
            if (1 !== preg_match('/^([a-z0-9]+)=([^=\s]+)\z/i', trim($element), $parts)) {
                return false;
            }

            if ('t' === $parts[1]) {
                if ($hasTimestamp || !ctype_digit($parts[2])) {
                    return false;
                }
                $hasTimestamp = true;
            } elseif (\in_array($parts[1], ['v1', 'v2'], true) && 1 !== preg_match('/^[a-f0-9]{64}\z/i', $parts[2])) {
                return false;
            }
        }

        return $hasTimestamp;
    }
}
