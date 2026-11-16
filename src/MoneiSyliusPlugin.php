<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin;

use Monei\SyliusPlugin\DependencyInjection\Compiler\RequireWorkflowPaymentGraphPass;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MoneiSyliusPlugin extends Bundle
{
    use SyliusPluginTrait;

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RequireWorkflowPaymentGraphPass());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
