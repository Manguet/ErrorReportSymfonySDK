<?php

namespace ErrorExplorer\ErrorReporter\Tests\Integration;

use ErrorExplorer\ErrorReporter\ErrorReporterBundle;
use ErrorExplorer\ErrorReporter\DependencyInjection\ErrorReporterExtension;
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use ErrorExplorer\ErrorReporter\EventListener\ErrorReportingListener;
use ErrorExplorer\ErrorReporter\Service\ErrorReporterInitializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class ErrorReporterBundleTest extends TestCase
{
    public function testBundleConstruction()
    {
        $bundle = new ErrorReporterBundle();
        
        $this->assertInstanceOf(ErrorReporterBundle::class, $bundle);
    }

    public function testBundleIntegration()
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test'
        ]));
        
        // Mock services that might not be available in test - mark as synthetic
        $container->register('http_client', 'Symfony\Contracts\HttpClient\HttpClientInterface')->setSynthetic(true);
        $container->register('logger', 'Psr\Log\LoggerInterface')->setSynthetic(true);
        $container->register('request_stack', 'Symfony\Component\HttpFoundation\RequestStack')->setSynthetic(true);
        
        $extension = new ErrorReporterExtension();
        
        $config = [
            [
                'webhook_url' => 'https://test.example.com',
                'token' => 'integration-test-token',
                'project_name' => 'integration-test-project',
                'enabled' => true,
                'ignore_exceptions' => ['TestException']
            ]
        ];
        
        $extension->load($config, $container);
        
        // Test that services are registered (without compilation)
        $this->assertTrue($container->hasDefinition('error_reporter.webhook_error_reporter'));
        $this->assertTrue($container->hasDefinition('error_reporter.error_reporting_listener'));
        $this->assertTrue($container->hasDefinition('error_reporter.initializer'));
        
        // Verify service definitions have the right class
        $webhookDefinition = $container->getDefinition('error_reporter.webhook_error_reporter');
        $this->assertEquals(WebhookErrorReporter::class, $webhookDefinition->getClass());
        
        $listenerDefinition = $container->getDefinition('error_reporter.error_reporting_listener');
        $this->assertEquals(ErrorReportingListener::class, $listenerDefinition->getClass());
        
        $initializerDefinition = $container->getDefinition('error_reporter.initializer');
        $this->assertEquals(ErrorReporterInitializer::class, $initializerDefinition->getClass());
    }

    public function testServiceConfiguration()
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'production'
        ]));
        
        // Mock dependencies
        $container->register('http_client', 'Symfony\Contracts\HttpClient\HttpClientInterface')->setSynthetic(true);
        $container->register('logger', 'Psr\Log\LoggerInterface')->setSynthetic(true);
        $container->register('request_stack', 'Symfony\Component\HttpFoundation\RequestStack')->setSynthetic(true);
        
        $extension = new ErrorReporterExtension();
        
        $config = [
            [
                'webhook_url' => 'https://prod.example.com',
                'token' => 'prod-token',
                'project_name' => 'prod-project',
                'enabled' => false,
                'ignore_exceptions' => [
                    'ProductionException',
                    'AnotherException'
                ]
            ]
        ];
        
        $extension->load($config, $container);
        
        // Test that configuration is properly passed to services
        $webhookDefinition = $container->getDefinition('error_reporter.webhook_error_reporter');
        $arguments = $webhookDefinition->getArguments();
        
        $this->assertEquals('https://prod.example.com', $arguments[0]);
        $this->assertEquals('prod-token', $arguments[1]);
        $this->assertEquals('prod-project', $arguments[2]);
        $this->assertFalse($arguments[3]); // enabled = false
        $this->assertEquals(['ProductionException', 'AnotherException'], $arguments[4]);
    }

    public function testEventListenerIsTagged()
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test'
        ]));
        
        // Mock dependencies
        $container->register('http_client', 'Symfony\Contracts\HttpClient\HttpClientInterface')->setSynthetic(true);
        $container->register('logger', 'Psr\Log\LoggerInterface')->setSynthetic(true);
        $container->register('request_stack', 'Symfony\Component\HttpFoundation\RequestStack')->setSynthetic(true);
        
        $extension = new ErrorReporterExtension();
        
        $config = [
            [
                'webhook_url' => 'https://example.com',
                'token' => 'test-token',
                'project_name' => 'test-project'
            ]
        ];
        
        $extension->load($config, $container);
        
        $definition = $container->getDefinition('error_reporter.error_reporting_listener');
        $tags = $definition->getTags();
        
        $this->assertArrayHasKey('kernel.event_subscriber', $tags);
    }
}