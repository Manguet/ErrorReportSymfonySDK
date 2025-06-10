<?php

namespace ErrorExplorer\ErrorReporter\Tests\Unit\DependencyInjection;

use ErrorExplorer\ErrorReporter\DependencyInjection\ErrorReporterExtension;
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use ErrorExplorer\ErrorReporter\Service\ErrorReporterInitializer;
use ErrorExplorer\ErrorReporter\EventListener\ErrorReportingListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ErrorReporterExtensionTest extends TestCase
{
    /** @var ErrorReporterExtension */
    private $extension;

    /** @var ContainerBuilder */
    private $container;

    protected function setUp(): void
    {
        $this->extension = new ErrorReporterExtension();
        $this->container = new ContainerBuilder();
    }

    public function testLoad()
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

    public function testWebhookErrorReporterConfiguration()
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
        $this->assertEquals('https://test.example.com', $arguments[0]);
        $this->assertEquals('secret-token', $arguments[1]);
        $this->assertEquals('my-project', $arguments[2]);
        $this->assertFalse($arguments[3]); // enabled
        $this->assertEquals([
            'App\\Exception\\IgnoredException',
            'Another\\Exception\\Class'
        ], $arguments[4]); // ignore_exceptions
    }

    public function testErrorReportingListenerConfiguration()
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
        $this->assertTrue($definition->hasTag('kernel.event_subscriber'));

        $arguments = $definition->getArguments();
        $this->assertCount(2, $arguments);

        // First argument should be a reference to the webhook error reporter
        $this->assertInstanceOf(
            \Symfony\Component\DependencyInjection\Reference::class,
            $arguments[0]
        );
        $this->assertEquals('error_reporter.webhook_error_reporter', (string) $arguments[0]);

        // Second argument should be the kernel environment parameter
        $this->assertEquals('%kernel.environment%', $arguments[1]);
    }

    public function testErrorReporterInitializerConfiguration()
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
            \Symfony\Component\DependencyInjection\Reference::class,
            $arguments[0]
        );
        $this->assertEquals('error_reporter.webhook_error_reporter', (string) $arguments[0]);
    }

    public function testGetAlias()
    {
        $this->assertEquals('error_reporter', $this->extension->getAlias());
    }

    public function testServiceReferences()
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
            \Symfony\Component\DependencyInjection\Reference::class,
            $arguments[5]
        ); // http_client
        $this->assertEquals('http_client', (string) $arguments[5]);

        $this->assertInstanceOf(
            \Symfony\Component\DependencyInjection\Reference::class,
            $arguments[6]
        ); // logger
        $this->assertEquals('logger', (string) $arguments[6]);

        $this->assertInstanceOf(
            \Symfony\Component\DependencyInjection\Reference::class,
            $arguments[7]
        ); // request_stack
        $this->assertEquals('request_stack', (string) $arguments[7]);
    }

    public function testMinimalConfiguration()
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

        $this->assertEquals('https://minimal.com', $arguments[0]);
        $this->assertEquals('minimal-token', $arguments[1]);
        $this->assertEquals('minimal-project', $arguments[2]);
        $this->assertTrue($arguments[3]); // enabled defaults to true
        $this->assertEquals([
            'Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException',
            'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException'
        ], $arguments[4]); // default ignore_exceptions
    }
}
