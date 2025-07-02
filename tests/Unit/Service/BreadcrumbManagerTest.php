<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Tests\Unit\Service;

use ErrorExplorer\ErrorReporter\Enum\BreadcrumbCategory;
use ErrorExplorer\ErrorReporter\Enum\LogLevel;
use ErrorExplorer\ErrorReporter\Service\BreadcrumbManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BreadcrumbManager::class)]
final class BreadcrumbManagerTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear breadcrumbs before each test
        BreadcrumbManager::clearBreadcrumbs();
    }

    public function testAddBreadcrumb(): void
    {
        BreadcrumbManager::addBreadcrumb('Test message', BreadcrumbCategory::CUSTOM, LogLevel::INFO, ['key' => 'value']);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Test message', $breadcrumbs[0]['message']);
        $this->assertEquals('custom', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['key' => 'value'], $breadcrumbs[0]['data']);
        $this->assertArrayHasKey('timestamp', $breadcrumbs[0]);
        $this->assertArrayHasKey('icon', $breadcrumbs[0]);
    }

    public function testAddBreadcrumbWithDefaults(): void
    {
        BreadcrumbManager::addBreadcrumb('Test message');

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Test message', $breadcrumbs[0]['message']);
        $this->assertEquals('custom', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals([], $breadcrumbs[0]['data']);
    }

    public function testMultipleBreadcrumbs(): void
    {
        BreadcrumbManager::addBreadcrumb('First message');
        BreadcrumbManager::addBreadcrumb('Second message');
        BreadcrumbManager::addBreadcrumb('Third message');

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(3, $breadcrumbs);
        $this->assertEquals('First message', $breadcrumbs[0]['message']);
        $this->assertEquals('Second message', $breadcrumbs[1]['message']);
        $this->assertEquals('Third message', $breadcrumbs[2]['message']);

        // Check chronological order
        $this->assertLessThanOrEqual($breadcrumbs[1]['timestamp'], $breadcrumbs[0]['timestamp']);
        $this->assertLessThanOrEqual($breadcrumbs[2]['timestamp'], $breadcrumbs[1]['timestamp']);
    }

    public function testMaxBreadcrumbsLimit(): void
    {
        // Set a low limit for testing (minimum is 10)
        BreadcrumbManager::setMaxBreadcrumbs(10);

        // Add more breadcrumbs than the limit
        for ($i = 1; $i <= 15; $i++) {
            BreadcrumbManager::addBreadcrumb("Message {$i}");
        }

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        // Should only keep the last 10
        $this->assertCount(10, $breadcrumbs);
        $this->assertEquals('Message 6', $breadcrumbs[0]['message']);
        $this->assertEquals('Message 15', $breadcrumbs[9]['message']);

        // Reset to default
        BreadcrumbManager::setMaxBreadcrumbs(50);
    }

    public function testClearBreadcrumbs(): void
    {
        BreadcrumbManager::addBreadcrumb('Test message 1');
        BreadcrumbManager::addBreadcrumb('Test message 2');

        $this->assertCount(2, BreadcrumbManager::getBreadcrumbs());

        BreadcrumbManager::clearBreadcrumbs();

        $this->assertCount(0, BreadcrumbManager::getBreadcrumbs());
    }

    public function testLogNavigation(): void
    {
        BreadcrumbManager::logNavigation('/home', '/profile', ['user_id' => 123]);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Navigation: /home → /profile', $breadcrumbs[0]['message']);
        $this->assertEquals('navigation', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['user_id' => 123, 'from' => '/home', 'to' => '/profile'], $breadcrumbs[0]['data']);
    }

    public function testLogUserAction(): void
    {
        BreadcrumbManager::logUserAction('clicked_button', ['button_id' => 'submit']);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('User action: clicked_button', $breadcrumbs[0]['message']);
        $this->assertEquals('user', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['button_id' => 'submit', 'action' => 'clicked_button'], $breadcrumbs[0]['data']);
    }

    public function testLogHttpRequest(): void
    {
        BreadcrumbManager::logHttpRequest('POST', '/api/users', 201, ['user_id' => 456]);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('HTTP POST /api/users [201]', $breadcrumbs[0]['message']);
        $this->assertEquals('http', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['user_id' => 456, 'method' => 'POST', 'url' => '/api/users', 'status_code' => 201], $breadcrumbs[0]['data']);
    }

    public function testLogHttpRequestWithoutStatusCode(): void
    {
        BreadcrumbManager::logHttpRequest('GET', '/api/users');

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('HTTP GET /api/users', $breadcrumbs[0]['message']);
        $this->assertEquals('http', $breadcrumbs[0]['category']);
    }

    public function testLogQuery(): void
    {
        BreadcrumbManager::logQuery('SELECT * FROM users WHERE id = ?', 25.5, ['query_id' => 'q123']);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Query: SELECT * FROM users WHERE id = ? (25.5ms)', $breadcrumbs[0]['message']);
        $this->assertEquals('query', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['query_id' => 'q123', 'query' => 'SELECT * FROM users WHERE id = ?', 'duration_ms' => 25.5], $breadcrumbs[0]['data']);
    }

    public function testLogQueryWithoutDuration(): void
    {
        BreadcrumbManager::logQuery('SELECT * FROM users');

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Query: SELECT * FROM users', $breadcrumbs[0]['message']);
        $this->assertEquals('query', $breadcrumbs[0]['category']);
    }

    public function testSetMaxBreadcrumbs(): void
    {
        // Test valid range
        BreadcrumbManager::setMaxBreadcrumbs(20);
        $this->assertEquals(20, BreadcrumbManager::getMaxBreadcrumbs());

        // Test minimum valid value
        BreadcrumbManager::setMaxBreadcrumbs(10);
        $this->assertEquals(10, BreadcrumbManager::getMaxBreadcrumbs());

        // Test maximum valid value
        BreadcrumbManager::setMaxBreadcrumbs(100);
        $this->assertEquals(100, BreadcrumbManager::getMaxBreadcrumbs());

        // Test invalid values
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max breadcrumbs must be between 10 and 100');
        BreadcrumbManager::setMaxBreadcrumbs(5);
    }

    public function testSetMaxBreadcrumbsAboveLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max breadcrumbs must be between 10 and 100');
        BreadcrumbManager::setMaxBreadcrumbs(101);
    }

    public function testBreadcrumbsAreStaticAcrossInstances(): void
    {
        BreadcrumbManager::addBreadcrumb('Global message');

        // Breadcrumbs should be accessible from different parts of the code
        $breadcrumbs1 = BreadcrumbManager::getBreadcrumbs();
        $breadcrumbs2 = BreadcrumbManager::getBreadcrumbs();

        $this->assertEquals($breadcrumbs1, $breadcrumbs2);
        $this->assertCount(1, $breadcrumbs1);
        $this->assertEquals('Global message', $breadcrumbs1[0]['message']);
    }
}
