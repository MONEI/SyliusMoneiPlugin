<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Capture, cancel and refund reach MONEI only through Symfony Workflow events of the
 * sylius_payment graph. With the winzou adapter they would change the Sylius state
 * without calling MONEI, so the container refuses to build.
 */
final class RequireWorkflowPaymentGraphPass implements CompilerPassInterface
{
    public const GRAPH = 'sylius_payment';

    private const WORKFLOW_ADAPTER = 'symfony_workflow';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('sylius_abstraction.state_machine.default_adapter')) {
            return;
        }

        /** @var string $adapter */
        $adapter = $container->getParameter('sylius_abstraction.state_machine.default_adapter');
        /** @var array<string, string> $mapping */
        $mapping = $container->hasParameter('sylius_abstraction.state_machine.graphs_to_adapters_mapping')
            ? $container->getParameter('sylius_abstraction.state_machine.graphs_to_adapters_mapping')
            : [];
        $adapter = $mapping[self::GRAPH] ?? $adapter;

        if (self::WORKFLOW_ADAPTER !== $adapter) {
            throw new \LogicException(sprintf(
                'The MONEI plugin needs the "%s" state machine adapter for the "%s" graph, but it is "%s". Set sylius_state_machine_abstraction.graphs_to_adapters_mapping.%s to "%s".',
                self::WORKFLOW_ADAPTER,
                self::GRAPH,
                $adapter,
                self::GRAPH,
                self::WORKFLOW_ADAPTER,
            ));
        }
    }
}
