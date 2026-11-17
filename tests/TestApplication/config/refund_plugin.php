<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    /** @var array<string, class-string> $bundles */
    $bundles = $builder->getParameter('kernel.bundles');
    if (!isset($bundles['SyliusRefundPlugin'])) {
        return;
    }

    $container->import('@SyliusRefundPlugin/config/config.yaml');
    // No wkhtmltopdf in the test image.
    $container->extension('sylius_refund', ['pdf_generator' => ['enabled' => false]]);
};
