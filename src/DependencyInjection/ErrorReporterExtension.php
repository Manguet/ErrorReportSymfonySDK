<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\DependencyInjection;

use ErrorExplorer\ErrorReporter\Enum\LogLevel;
use ErrorExplorer\ErrorReporter\EventListener\ErrorReportingListener;
use ErrorExplorer\ErrorReporter\Service\ErrorReporterInitializer;
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class ErrorReporterExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $this->registerWebhookErrorReporter($container, $config);
        $this->registerErrorReportingListener($container);
        $this->registerErrorReporterInitializer($container);
    }

    private function registerWebhookErrorReporter(ContainerBuilder $container, array $config): void
    {
        $definition = new Definition(WebhookErrorReporter::class, [
            '$webhookUrl' => $config['webhook_url'],
            '$token' => $config['token'],
            '$projectName' => $config['project_name'],
            '$enabled' => $config['enabled'],
            '$ignoredExceptions' => $config['ignore_exceptions'],
            '$timeout' => $config['http_client']['timeout'],
            '$maxRetries' => $config['http_client']['max_retries'],
            '$minimumLevel' => LogLevel::from($config['minimum_level']),
            '$httpClient' => new Reference('http_client'),
            '$logger' => new Reference('logger'),
            '$requestStack' => new Reference('request_stack'),
        ]);

        $container->setDefinition('error_reporter.webhook_error_reporter', $definition);
    }

    private function registerErrorReportingListener(ContainerBuilder $container): void
    {
        $definition = new Definition(ErrorReportingListener::class, [
            '$errorReporter' => new Reference('error_reporter.webhook_error_reporter'),
            '$environment' => '%kernel.environment%',
        ]);

        $definition->addTag('kernel.event_listener');

        $container->setDefinition('error_reporter.error_reporting_listener', $definition);
    }

    private function registerErrorReporterInitializer(ContainerBuilder $container): void
    {
        $definition = new Definition(ErrorReporterInitializer::class, [
            '$errorReporter' => new Reference('error_reporter.webhook_error_reporter'),
        ]);

        $container->setDefinition('error_reporter.initializer', $definition);
    }

    public function getAlias(): string
    {
        return 'error_reporter';
    }
}
