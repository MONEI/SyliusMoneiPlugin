<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\DependencyInjection\Compiler;

use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Lets the admin pick a MONEI payment method as the refund method of sylius/refund-plugin.
 * A compiler pass, not a prepend: the refund plugin sets the parameter when it loads, after
 * every prepend, and keeps only a value the application itself configured.
 */
final class AddMoneiRefundGatewayPass implements CompilerPassInterface
{
    private const PARAMETER = 'sylius_refund.supported_gateways';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER)) {
            return;
        }

        /** @var list<string> $gateways */
        $gateways = $container->getParameter(self::PARAMETER);
        if (!\in_array(GatewayConfiguration::FACTORY_NAME, $gateways, true)) {
            $gateways[] = GatewayConfiguration::FACTORY_NAME;
            $container->setParameter(self::PARAMETER, $gateways);
        }
    }
}
