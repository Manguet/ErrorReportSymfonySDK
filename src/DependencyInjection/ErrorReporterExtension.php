<?php

namespace ErrorExplorer\ErrorReporter\DependencyInjection;

use ErrorExplorer\ErrorReporter\EventListener\ErrorReportingListener;
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use ErrorExplorer\ErrorReporter\Service\ErrorReporterInitializer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

class ErrorReporterExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $this->registerWebhookErrorReporter($container, $config);
        $this->registerErrorReportingListener($container);
        $this->registerErrorReporterInitializer($container);
    }

    private function registerWebhookErrorReporter(ContainerBuilder $container, array $config)
    {
        $definition = new Definition(WebhookErrorReporter::class, [
            $config['webhook_url'],
            $config['token'],
            $config['project_name'],
            $config['enabled'],
            $config['ignore_exceptions'],
            new Reference('http_client'),
            new Reference('logger'),
            new Reference('request_stack'),
        ]);

        $container->setDefinition('error_reporter.webhook_error_reporter', $definition);
    }

    private function registerErrorReportingListener(ContainerBuilder $container)
    {
        $definition = new Definition(ErrorReportingListener::class, [
            new Reference('error_reporter.webhook_error_reporter'),
            '%kernel.environment%',
        ]);

        $definition->addTag('kernel.event_subscriber');

        $container->setDefinition('error_reporter.error_reporting_listener', $definition);
    }

    private function registerErrorReporterInitializer(ContainerBuilder $container)
    {
        $definition = new Definition(ErrorReporterInitializer::class, [
            new Reference('error_reporter.webhook_error_reporter'),
        ]);

        $container->setDefinition('error_reporter.initializer', $definition);
    }

    public function getAlias(): string
    {
        return 'error_reporter';
    }
}
