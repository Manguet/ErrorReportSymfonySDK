<?php

namespace ErrorExplorer\ErrorReporter\Tests\Unit\Service;

use ErrorExplorer\ErrorReporter\ErrorReporter;
use ErrorExplorer\ErrorReporter\Service\ErrorReporterInitializer;
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use PHPUnit\Framework\TestCase;

class ErrorReporterInitializerTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset static state
        $reflection = new \ReflectionClass(ErrorReporter::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    public function testInitializerSetsStaticInstance(): void
    {
        $webhookReporter = $this->createMock(WebhookErrorReporter::class);

        // Before initialization, should not be configured
        $this->assertFalse(ErrorReporter::isConfigured());

        // Create initializer (this should set the static instance)
        $initializer = new ErrorReporterInitializer($webhookReporter);

        // After initialization, should be configured
        $this->assertTrue(ErrorReporter::isConfigured());
    }

    public function testGetWebhookErrorReporter(): void
    {
        $webhookReporter = $this->createMock(WebhookErrorReporter::class);

        $initializer = new ErrorReporterInitializer($webhookReporter);

        $this->assertSame($webhookReporter, $initializer->getWebhookErrorReporter());
    }

    public function testStaticErrorReporterWorksAfterInitialization(): void
    {
        $webhookReporter = $this->createMock(WebhookErrorReporter::class);
        $exception = new \Exception('Test exception');

        // Set up expectation for reportError call
        $webhookReporter->expects($this->once())
            ->method('reportError')
            ->with($exception, 'test', 500, null);

        // Initialize
        new ErrorReporterInitializer($webhookReporter);

        // Now static calls should work
        ErrorReporter::reportError($exception, 'test', 500);
    }
}
