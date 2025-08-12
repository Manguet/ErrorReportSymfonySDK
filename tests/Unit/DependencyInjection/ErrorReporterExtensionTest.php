<?php

namespace ErrorExplorer\ErrorReporter\Tests\Unit\DependencyInjection;

use ErrorExplorer\ErrorReporter\DependencyInjection\ErrorReporterExtension;
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use ErrorExplorer\ErrorReporter\Service\ErrorReporterInitializer;
use ErrorExplorer\ErrorReporter\EventListener\ErrorReportingListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ErrorReporterExtensionTest extends TestCase
{
    private ErrorReporterExtension $extension;
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new ErrorReporterExtension();
        $this->container = new ContainerBuilder();
    }

    public function testLoad(): void
    {
        $config = [
            'error_reporter' => [
                'webhook_url' => 'https://example.com',
                'token' => 'test-token',
                'project_name' => 'test-project',
                'enabled' => true,
                'ignore_exceptions' => ['App\\Exception\\TestException']
            ]
        ];

        $this->extension->load($config, $this->container);

        // Check that services are registered
        $this->assertTrue($this->container->hasDefinition('error_reporter.webhook_error_reporter'));
        $this->assertTrue($this->container->hasDefinition('error_reporter.error_reporting_listener'));
        $this->assertTrue($this->container->hasDefinition('error_reporter.initializer'));
    }

    public function testWebhookErrorReporterConfiguration(): void
    {
        $config = [
            'error_reporter' => [
                'webhook_url' => 'https://test.example.com',
                'token' => 'secret-token',
                'project_name' => 'my-project',
                'enabled' => false,
                'ignore_exceptions' => [
                    'App\\Exception\\IgnoredException',
                    'Another\\Exception\\Class'
                ]
            ]
        ];

        $this->extension->load($config, $this->container);

        $definition = $this->container->getDefinition('error_reporter.webhook_error_reporter');

        $this->assertEquals(WebhookErrorReporter::class, $definition->getClass());

        $arguments = $definition->getArguments();
        $this->assertEquals('https://test.example.com', $arguments['$webhookUrl']);
        $this->assertEquals('secret-token', $arguments['$token']);
        $this->assertEquals('my-project', $arguments['$projectName']);
        $this->assertFalse($arguments['$enabled']); // enabled
        $this->assertEquals([
            'App\\Exception\\IgnoredException',
            'Another\\Exception\\Class'
        ], $arguments['$ignoredExceptions']); // ignore_exceptions
    }

    public function testErrorReportingListenerConfiguration(): void
    {
        $config = [
            'error_reporter' => [
                'webhook_url' => 'https://example.com',
                'token' => 'test-token',
                'project_name' => 'test-project'
            ]
        ];

        $this->extension->load($config, $this->container);

        $definition = $this->container->getDefinition('error_reporter.error_reporting_listener');

        $this->assertEquals(ErrorReportingListener::class, $definition->getClass());
        $this->assertTrue($definition->hasTag('kernel.event_listener'));

        $arguments = $definition->getArguments();
        $this->assertCount(2, $arguments);

        // First argument should be a reference to the webhook error reporter
        $this->assertInstanceOf(
            Reference::class,
            $arguments['$errorReporter']
        );
        $this->assertEquals('error_reporter.webhook_error_reporter', (string) $arguments['$errorReporter']);

        // Second argument should be the kernel environment parameter
        $this->assertEquals('%kernel.environment%', $arguments['$environment']);
    }

    public function testErrorReporterInitializerConfiguration(): void
    {
        $config = [
            'error_reporter' => [
                'webhook_url' => 'https://example.com',
                'token' => 'test-token',
                'project_name' => 'test-project'
            ]
        ];

        $this->extension->load($config, $this->container);

        $definition = $this->container->getDefinition('error_reporter.initializer');

        $this->assertEquals(ErrorReporterInitializer::class, $definition->getClass());

        $arguments = $definition->getArguments();
        $this->assertCount(1, $arguments);

        // Argument should be a reference to the webhook error reporter
        $this->assertInstanceOf(
            Reference::class,
            $arguments['$errorReporter']
        );
        $this->assertEquals('error_reporter.webhook_error_reporter', (string) $arguments['$errorReporter']);
    }

    public function testGetAlias(): void
    {
        $this->assertEquals('error_reporter', $this->extension->getAlias());
    }

    public function testServiceReferences(): void
    {
        $config = [
            'error_reporter' => [
                'webhook_url' => 'https://example.com',
                'token' => 'test-token',
                'project_name' => 'test-project'
            ]
        ];

        $this->extension->load($config, $this->container);

        $webhookDefinition = $this->container->getDefinition('error_reporter.webhook_error_reporter');
        $arguments = $webhookDefinition->getArguments();

        // Check optional service references
        $this->assertInstanceOf(
            Reference::class,
            $arguments['$httpClient']
        ); // http_client
        $this->assertEquals('http_client', (string) $arguments['$httpClient']);

        $this->assertInstanceOf(
            Reference::class,
            $arguments['$logger']
        ); // logger
        $this->assertEquals('logger', (string) $arguments['$logger']);

        $this->assertInstanceOf(
            Reference::class,
            $arguments['$requestStack']
        ); // request_stack
        $this->assertEquals('request_stack', (string) $arguments['$requestStack']);
    }

    public function testMinimalConfiguration(): void
    {
        $config = [
            'error_reporter' => [
                'webhook_url' => 'https://minimal.com',
                'token' => 'minimal-token',
                'project_name' => 'minimal-project'
            ]
        ];

        $this->extension->load($config, $this->container);

        $definition = $this->container->getDefinition('error_reporter.webhook_error_reporter');
        $arguments = $definition->getArguments();

        $this->assertEquals('https://minimal.com', $arguments['$webhookUrl']);
        $this->assertEquals('minimal-token', $arguments['$token']);
        $this->assertEquals('minimal-project', $arguments['$projectName']);
        $this->assertTrue($arguments['$enabled']); // enabled defaults to true
        $this->assertEquals([
            AccessDeniedException::class,
            NotFoundHttpException::class
        ], $arguments['$ignoredExceptions']); // default ignore_exceptions
    }
}
