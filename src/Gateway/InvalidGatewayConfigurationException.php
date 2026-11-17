<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Gateway;

final class InvalidGatewayConfigurationException extends \InvalidArgumentException
{
    /**
     * @param string $field          the gateway config key that breaks the rule
     * @param string $translationKey message key in the `validators` domain
     */
    public function __construct(
        public readonly string $field,
        public readonly string $translationKey,
        string $message,
    ) {
        parent::__construct($message);
    }
}
