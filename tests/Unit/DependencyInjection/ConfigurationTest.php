<?php

namespace ErrorExplorer\ErrorReporter\Tests\Unit\DependencyInjection;

use ErrorExplorer\ErrorReporter\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ConfigurationTest extends TestCase
{
    private Configuration $configuration;
    private Processor $processor;

    protected function setUp(): void
    {
        $this->configuration = new Configuration();
        $this->processor = new Processor();
    }

    public function testDefaultConfiguration(): void
    {
        $config = $this->processor->processConfiguration(
            $this->configuration,
            [
                'error_reporter' => [
                    'webhook_url' => 'https://example.com',
                    'token' => 'test-token',
                    'project_name' => 'test-project'
                ]
            ]
        );

        $this->assertEquals('https://example.com', $config['webhook_url']);
        $this->assertEquals('test-token', $config['token']);
        $this->assertEquals('test-project', $config['project_name']);
        $this->assertTrue($config['enabled']); // Default should be true
        $this->assertEquals([
            AccessDeniedException::class,
            NotFoundHttpException::class,
        ], $config['ignore_exceptions']);
    }

    public function testCustomConfiguration(): void
    {
        $config = $this->processor->processConfiguration(
            $this->configuration,
            [
                'error_reporter' => [
                    'webhook_url' => 'https://custom.com',
                    'token' => 'custom-token',
                    'project_name' => 'custom-project',
                    'enabled' => false,
                    'ignore_exceptions' => [
                        'App\\Exception\\CustomException',
                        'Another\\Exception\\Class'
                    ]
                ]
            ]
        );

        $this->assertEquals('https://custom.com', $config['webhook_url']);
        $this->assertEquals('custom-token', $config['token']);
        $this->assertEquals('custom-project', $config['project_name']);
        $this->assertFalse($config['enabled']);
        $this->assertEquals([
            'App\\Exception\\CustomException',
            'Another\\Exception\\Class'
        ], $config['ignore_exceptions']);
    }

    public function testMissingWebhookUrl(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The child config "webhook_url" under "error_reporter" must be configured');

        $this->processor->processConfiguration(
            $this->configuration,
            [
                'error_reporter' => [
                    'token' => 'test-token',
                    'project_name' => 'test-project'
                ]
            ]
        );
    }

    public function testMissingToken(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The child config "token" under "error_reporter" must be configured');

        $this->processor->processConfiguration(
            $this->configuration,
            [
                'error_reporter' => [
                    'webhook_url' => 'https://example.com',
                    'project_name' => 'test-project'
                ]
            ]
        );
    }

    public function testMissingProjectName(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The child config "project_name" under "error_reporter" must be configured');

        $this->processor->processConfiguration(
            $this->configuration,
            [
                'error_reporter' => [
                    'webhook_url' => 'https://example.com',
                    'token' => 'test-token'
                ]
            ]
        );
    }

    public function testEmptyWebhookUrl(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration(
            $this->configuration,
            [
                'error_reporter' => [
                    'webhook_url' => '',
                    'token' => 'test-token',
                    'project_name' => 'test-project'
                ]
            ]
        );
    }

    public function testEmptyToken(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration(
            $this->configuration,
            [
                'error_reporter' => [
                    'webhook_url' => 'https://example.com',
                    'token' => '',
                    'project_name' => 'test-project'
                ]
            ]
        );
    }

    public function testEmptyProjectName(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration(
            $this->configuration,
            [
                'error_reporter' => [
                    'webhook_url' => 'https://example.com',
                    'token' => 'test-token',
                    'project_name' => ''
                ]
            ]
        );
    }

    public function testIgnoreExceptionsArray(): void
    {
        $config = $this->processor->processConfiguration(
            $this->configuration,
            [
                'error_reporter' => [
                    'webhook_url' => 'https://example.com',
                    'token' => 'test-token',
                    'project_name' => 'test-project',
                    'ignore_exceptions' => [
                        'Exception\\One',
                        'Exception\\Two',
                        'Exception\\Three'
                    ]
                ]
            ]
        );

        $this->assertEquals([
            'Exception\\One',
            'Exception\\Two',
            'Exception\\Three'
        ], $config['ignore_exceptions']);
    }

    public function testEmptyIgnoreExceptionsArray(): void
    {
        $config = $this->processor->processConfiguration(
            $this->configuration,
            [
                'error_reporter' => [
                    'webhook_url' => 'https://example.com',
                    'token' => 'test-token',
                    'project_name' => 'test-project',
                    'ignore_exceptions' => []
                ]
            ]
        );

        $this->assertEquals([], $config['ignore_exceptions']);
    }
}
