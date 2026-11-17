<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Yaml\Yaml;

final class MoneiSyliusExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('monolog', ['channels' => ['monei']]);

        foreach (['admin', 'shop'] as $section) {
            $twigHooks = \dirname(__DIR__, 2).'/config/twig_hooks/'.$section.'.yaml';
            $container->addResource(new FileResource($twigHooks));
            /** @var array{sylius_twig_hooks: array<string, mixed>} $config */
            $config = Yaml::parseFile($twigHooks);
            $container->prependExtensionConfig('sylius_twig_hooks', $config['sylius_twig_hooks']);
        }
    }
}
