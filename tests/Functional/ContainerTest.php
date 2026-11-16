<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional;

use Monei\SyliusPlugin\Form\Type\MoneiGatewayConfigurationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class ContainerTest extends KernelTestCase
{
    public function testMoneiGatewayFactoryIsRegisteredWithItsConfigurationForm(): void
    {
        $container = self::getContainer();

        $factories = $container->getParameter('sylius.gateway_factories');
        self::assertIsArray($factories);
        self::assertSame('monei.gateway_factory.monei', $factories['monei'] ?? null);

        $formType = $container->get('sylius.form_registry.payment_gateway_config')->get('gateway_config', 'monei');
        self::assertSame(MoneiGatewayConfigurationType::class, $formType);

        $formFactory = $container->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);
        self::assertTrue($formFactory->create($formType)->has('api_key'));
    }
}
