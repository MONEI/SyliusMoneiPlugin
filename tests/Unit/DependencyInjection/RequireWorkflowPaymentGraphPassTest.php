<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\DependencyInjection;

use Monei\SyliusPlugin\DependencyInjection\Compiler\RequireWorkflowPaymentGraphPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RequireWorkflowPaymentGraphPassTest extends TestCase
{
    public function testDefaultWinzouAdapterIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->process('winzou_state_machine', []);
    }

    public function testPaymentGraphMappedToWinzouIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"sylius_payment"');

        $this->process('symfony_workflow', ['sylius_payment' => 'winzou_state_machine']);
    }

    public function testOrderGraphMappedToWinzouIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"sylius_order"');

        $this->process('symfony_workflow', ['sylius_order' => 'winzou_state_machine']);
    }

    public function testBothGraphsMappedToWorkflowPassOnWinzouDefault(): void
    {
        $this->expectNotToPerformAssertions();

        $this->process('winzou_state_machine', [
            'sylius_payment' => 'symfony_workflow',
            'sylius_order' => 'symfony_workflow',
        ]);
    }

    /** @param array<string, string> $mapping */
    private function process(string $defaultAdapter, array $mapping): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('sylius_abstraction.state_machine.default_adapter', $defaultAdapter);
        $container->setParameter('sylius_abstraction.state_machine.graphs_to_adapters_mapping', $mapping);

        (new RequireWorkflowPaymentGraphPass())->process($container);
    }
}
