<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('monei_sylius');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('api_key')->defaultNull()->end()
                ->scalarNode('account_id')->defaultNull()->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
