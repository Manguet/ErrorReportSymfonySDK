<?php

namespace ErrorExplorer\ErrorReporter\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('error_reporter');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('webhook_url')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('The Error Explorer webhook URL')
                ->end()
                ->scalarNode('token')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('The unique project token for authentication')
                ->end()
                ->scalarNode('project_name')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('The project name identifier')
                ->end()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Enable or disable error reporting')
                ->end()
                ->arrayNode('ignore_exceptions')
                    ->scalarPrototype()->end()
                    ->defaultValue([
                        'Symfony\Component\Security\Core\Exception\AccessDeniedException',
                        'Symfony\Component\HttpKernel\Exception\NotFoundHttpException'
                    ])
                    ->info('List of exception classes to ignore')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
