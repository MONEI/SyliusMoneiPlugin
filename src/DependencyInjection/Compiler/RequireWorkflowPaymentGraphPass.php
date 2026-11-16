<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Capture, cancel and refund reach MONEI only through Symfony Workflow events of the
 * sylius_payment graph, and the order cancel guard through the sylius_order graph.
 * With another adapter they would change the Sylius state without calling MONEI, so
 * the container refuses to build. An adapter set from an env var is unknown at compile
 * time and is not checked.
 */
final class RequireWorkflowPaymentGraphPass implements CompilerPassInterface
{
    private const GRAPHS = ['sylius_payment', 'sylius_order'];

    private const WORKFLOW_ADAPTER = 'symfony_workflow';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('sylius_abstraction.state_machine.default_adapter')) {
            return;
        }

        $bag = $container->getParameterBag();
        $default = $bag->resolveValue($container->getParameter('sylius_abstraction.state_machine.default_adapter'));
        $mapping = $container->hasParameter('sylius_abstraction.state_machine.graphs_to_adapters_mapping')
            ? $bag->resolveValue($container->getParameter('sylius_abstraction.state_machine.graphs_to_adapters_mapping'))
            : [];

        foreach (self::GRAPHS as $graph) {
            $adapter = \is_array($mapping) && \array_key_exists($graph, $mapping) ? $mapping[$graph] : $default;

            $usedEnvs = [];
            $container->resolveEnvPlaceholders($adapter, null, $usedEnvs);
            if ([] !== $usedEnvs || self::WORKFLOW_ADAPTER === $adapter) {
                continue;
            }

            throw new \LogicException(\sprintf(
                'The MONEI plugin needs the "%s" state machine adapter for the "%s" graph, but it is "%s". Set sylius_state_machine_abstraction.graphs_to_adapters_mapping.%s to "%s".',
                self::WORKFLOW_ADAPTER,
                $graph,
                \is_string($adapter) ? $adapter : get_debug_type($adapter),
                $graph,
                self::WORKFLOW_ADAPTER,
            ));
        }
    }
}
