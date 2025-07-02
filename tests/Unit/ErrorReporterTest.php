<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Tests\Unit;

use ErrorExplorer\ErrorReporter\Enum\BreadcrumbCategory;
use ErrorExplorer\ErrorReporter\Enum\LogLevel;
use ErrorExplorer\ErrorReporter\ErrorReporter;
use ErrorExplorer\ErrorReporter\Service\BreadcrumbManager;
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

#[CoversClass(ErrorReporter::class)]
final class ErrorReporterTest extends TestCase
{
    private WebhookErrorReporter $webhookReporter;

    protected function setUp(): void
    {
        $this->webhookReporter = $this->createMock(WebhookErrorReporter::class);

        // Reset the static instance
        ErrorReporter::setInstance($this->webhookReporter);

        // Clear breadcrumbs
        BreadcrumbManager::clearBreadcrumbs();
    }

    protected function tearDown(): void
    {
        // Reset static state using modern reflection
        $reflection = new \ReflectionClass(ErrorReporter::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, null);

        BreadcrumbManager::clearBreadcrumbs();
        
        parent::tearDown();
    }

    public function testSetInstance(): void
    {
        $reporter = $this->createMock(WebhookErrorReporter::class);
        ErrorReporter::setInstance($reporter);

        $this->assertTrue(ErrorReporter::isConfigured());
    }

    public function testIsConfiguredWhenNotSet(): void
    {
        // Reset instance using modern reflection
        $reflection = new \ReflectionClass(ErrorReporter::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, null);

        $this->assertFalse(ErrorReporter::isConfigured());
    }

    public function testReportError(): void
    {
        $exception = new \Exception('Test error');
        $request = Request::create('/test');

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with($exception, 'staging', 404, $request);

        ErrorReporter::reportError($exception, 'staging', 404, $request);
    }

    public function testReportErrorWithDefaults(): void
    {
        $exception = new \Exception('Test error');

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with($exception, 'prod', null, null);

        ErrorReporter::reportError($exception);
    }

    public function testReportErrorWhenNotConfigured(): void
    {
        // Reset instance
        $reflection = new \ReflectionClass(ErrorReporter::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);

        $exception = new \Exception('Test error');

        // Should not throw exception, should log error instead
        $this->expectOutputString('');
        ErrorReporter::reportError($exception);
    }

    public function testReport(): void
    {
        $exception = new \Exception('Test error');

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with($exception, 'prod', null, null);

        ErrorReporter::report($exception);
    }

    public function testReportWithContext(): void
    {
        $exception = new \Exception('Test error');

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with($exception, 'staging', 500, null);

        ErrorReporter::reportWithContext($exception, 'staging', 500);
    }

    public function testReportMessage(): void
    {
        $request = Request::create('/test');

        $this->webhookReporter->expects($this->once())
            ->method('reportMessage')
            ->with('Custom message', 'staging', 400, $request, LogLevel::WARNING, ['key' => 'value']);

        ErrorReporter::reportMessage('Custom message', 'staging', 400, $request, LogLevel::WARNING, ['key' => 'value']);
    }

    public function testReportMessageWithDefaults(): void
    {
        $this->webhookReporter->expects($this->once())
            ->method('reportMessage')
            ->with('Custom message', 'prod', null, null, LogLevel::ERROR, []);

        ErrorReporter::reportMessage('Custom message');
    }

    public function testReportMessageWhenNotConfigured(): void
    {
        // Reset instance
        $reflection = new \ReflectionClass(ErrorReporter::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);

        // Should not throw exception, should log error instead
        $this->expectOutputString('');
        ErrorReporter::reportMessage('Custom message');
    }

    public function testAddBreadcrumb(): void
    {
        ErrorReporter::addBreadcrumb('Test message', BreadcrumbCategory::CUSTOM, LogLevel::INFO, ['key' => 'value']);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Test message', $breadcrumbs[0]['message']);
        $this->assertEquals('custom', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['key' => 'value'], $breadcrumbs[0]['data']);
    }

    public function testLogNavigation(): void
    {
        ErrorReporter::logNavigation('/home', '/profile', ['user_id' => 123]);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Navigation: /home → /profile', $breadcrumbs[0]['message']);
        $this->assertEquals('navigation', $breadcrumbs[0]['category']);
        $this->assertEquals(['user_id' => 123, 'from' => '/home', 'to' => '/profile'], $breadcrumbs[0]['data']);
    }

    public function testLogUserAction(): void
    {
        ErrorReporter::logUserAction('clicked_button', ['button_id' => 'submit']);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('User action: clicked_button', $breadcrumbs[0]['message']);
        $this->assertEquals('user', $breadcrumbs[0]['category']);
        $this->assertEquals(['button_id' => 'submit', 'action' => 'clicked_button'], $breadcrumbs[0]['data']);
    }

    public function testLogHttpRequest(): void
    {
        ErrorReporter::logHttpRequest('POST', '/api/users', 201, ['user_id' => 456]);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('HTTP POST /api/users [201]', $breadcrumbs[0]['message']);
        $this->assertEquals('http', $breadcrumbs[0]['category']);
        $this->assertEquals(['user_id' => 456, 'method' => 'POST', 'url' => '/api/users', 'status_code' => 201], $breadcrumbs[0]['data']);
    }

    public function testLogQuery(): void
    {
        ErrorReporter::logQuery('SELECT * FROM users', 25.5, ['query_id' => 'q123']);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Query: SELECT * FROM users (25.5ms)', $breadcrumbs[0]['message']);
        $this->assertEquals('query', $breadcrumbs[0]['category']);
        $this->assertEquals(['query_id' => 'q123', 'query' => 'SELECT * FROM users', 'duration_ms' => 25.5], $breadcrumbs[0]['data']);
    }

    public function testClearBreadcrumbs(): void
    {
        ErrorReporter::addBreadcrumb('Test message 1');
        ErrorReporter::addBreadcrumb('Test message 2');

        $this->assertCount(2, BreadcrumbManager::getBreadcrumbs());

        ErrorReporter::clearBreadcrumbs();

        $this->assertCount(0, BreadcrumbManager::getBreadcrumbs());
    }
}
