<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\DependencyInjection;

use ErrorExplorer\ErrorReporter\Enum\LogLevel;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('error_reporter');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('webhook_url')
                    ->isRequired()
                    ->info('The Error Explorer webhook URL')
                ->end()
                ->scalarNode('token')
                    ->isRequired()
                    ->info('The unique project token for authentication')
                ->end()
                ->scalarNode('project_name')
                    ->isRequired()
                    ->info('The project name identifier')
                ->end()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Enable or disable error reporting')
                ->end()
                ->enumNode('minimum_level')
                    ->values(array_map(fn(LogLevel $level): string => $level->value, LogLevel::cases()))
                    ->defaultValue(LogLevel::ERROR->value)
                    ->info('Minimum log level to report')
                ->end()
                ->arrayNode('ignore_exceptions')
                    ->scalarPrototype()->end()
                    ->defaultValue([
                        'Symfony\Component\Security\Core\Exception\AccessDeniedException',
                        'Symfony\Component\HttpKernel\Exception\NotFoundHttpException'
                    ])
                    ->info('List of exception classes to ignore')
                ->end()
                ->arrayNode('http_client')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('timeout')
                            ->min(1)
                            ->max(30)
                            ->defaultValue(5)
                            ->info('HTTP client timeout in seconds')
                        ->end()
                        ->integerNode('max_retries')
                            ->min(0)
                            ->max(5)
                            ->defaultValue(3)
                            ->info('Maximum number of retry attempts')
                        ->end()
                        ->booleanNode('verify_ssl')
                            ->defaultTrue()
                            ->info('Verify SSL certificates')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('breadcrumbs')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable breadcrumb collection')
                        ->end()
                        ->integerNode('max_breadcrumbs')
                            ->min(10)
                            ->max(100)
                            ->defaultValue(50)
                            ->info('Maximum number of breadcrumbs to keep')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
