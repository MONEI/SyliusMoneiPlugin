<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Yaml\Yaml;

final class MoneiSyliusExtension extends Extension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $root = \dirname(__DIR__, 2);
        $this->prependDoctrineMigrations($container);
        $container->prependExtensionConfig('doctrine', ['orm' => ['mappings' => [
            'MoneiSyliusPlugin' => [
                'is_bundle' => false,
                'type' => 'xml',
                'dir' => $root.'/config/doctrine',
                'prefix' => 'Monei\SyliusPlugin\Entity',
            ],
        ]]]);
        $container->prependExtensionConfig('monolog', ['channels' => ['monei']]);

        foreach (['admin', 'shop'] as $section) {
            $twigHooks = $root.'/config/twig_hooks/'.$section.'.yaml';
            $container->addResource(new FileResource($twigHooks));
            /** @var array{sylius_twig_hooks: array<string, mixed>} $config */
            $config = Yaml::parseFile($twigHooks);
            $container->prependExtensionConfig('sylius_twig_hooks', $config['sylius_twig_hooks']);
        }
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Monei\SyliusPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return \dirname(__DIR__).'/Migrations';
    }

    /** @return list<string> */
    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [];
    }
}
