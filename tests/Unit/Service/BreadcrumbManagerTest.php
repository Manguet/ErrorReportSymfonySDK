<?php

namespace ErrorExplorer\ErrorReporter\Tests\Unit\Service;

use ErrorExplorer\ErrorReporter\Service\BreadcrumbManager;
use PHPUnit\Framework\TestCase;

class BreadcrumbManagerTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear breadcrumbs before each test
        BreadcrumbManager::clearBreadcrumbs();
    }

    public function testAddBreadcrumb()
    {
        BreadcrumbManager::addBreadcrumb('Test message', 'test', 'info', ['key' => 'value']);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Test message', $breadcrumbs[0]['message']);
        $this->assertEquals('test', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['key' => 'value'], $breadcrumbs[0]['data']);
        $this->assertArrayHasKey('timestamp', $breadcrumbs[0]);
        $this->assertIsFloat($breadcrumbs[0]['timestamp']);
    }

    public function testAddBreadcrumbWithDefaults()
    {
        BreadcrumbManager::addBreadcrumb('Test message');

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Test message', $breadcrumbs[0]['message']);
        $this->assertEquals('custom', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals([], $breadcrumbs[0]['data']);
    }

    public function testMultipleBreadcrumbs()
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

    public function testMaxBreadcrumbsLimit()
    {
        // Set a low limit for testing
        BreadcrumbManager::setMaxBreadcrumbs(3);

        // Add more breadcrumbs than the limit
        BreadcrumbManager::addBreadcrumb('Message 1');
        BreadcrumbManager::addBreadcrumb('Message 2');
        BreadcrumbManager::addBreadcrumb('Message 3');
        BreadcrumbManager::addBreadcrumb('Message 4');
        BreadcrumbManager::addBreadcrumb('Message 5');

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        // Should only keep the last 3
        $this->assertCount(3, $breadcrumbs);
        $this->assertEquals('Message 3', $breadcrumbs[0]['message']);
        $this->assertEquals('Message 4', $breadcrumbs[1]['message']);
        $this->assertEquals('Message 5', $breadcrumbs[2]['message']);

        // Reset to default
        BreadcrumbManager::setMaxBreadcrumbs(50);
    }

    public function testClearBreadcrumbs()
    {
        BreadcrumbManager::addBreadcrumb('Test message 1');
        BreadcrumbManager::addBreadcrumb('Test message 2');

        $this->assertCount(2, BreadcrumbManager::getBreadcrumbs());

        BreadcrumbManager::clearBreadcrumbs();

        $this->assertCount(0, BreadcrumbManager::getBreadcrumbs());
    }

    public function testLogNavigation()
    {
        BreadcrumbManager::logNavigation('/home', '/profile', ['user_id' => 123]);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Navigation: /home -> /profile', $breadcrumbs[0]['message']);
        $this->assertEquals('navigation', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['user_id' => 123], $breadcrumbs[0]['data']);
    }

    public function testLogUserAction()
    {
        BreadcrumbManager::logUserAction('clicked_button', ['button_id' => 'submit']);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('User action: clicked_button', $breadcrumbs[0]['message']);
        $this->assertEquals('user', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['button_id' => 'submit'], $breadcrumbs[0]['data']);
    }

    public function testLogHttpRequest()
    {
        BreadcrumbManager::logHttpRequest('POST', '/api/users', 201, ['user_id' => 456]);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('HTTP POST /api/users [201]', $breadcrumbs[0]['message']);
        $this->assertEquals('http', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['user_id' => 456], $breadcrumbs[0]['data']);
    }

    public function testLogHttpRequestWithoutStatusCode()
    {
        BreadcrumbManager::logHttpRequest('GET', '/api/users', null, []);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('HTTP GET /api/users', $breadcrumbs[0]['message']);
        $this->assertEquals('http', $breadcrumbs[0]['category']);
    }

    public function testLogQuery()
    {
        BreadcrumbManager::logQuery('SELECT * FROM users WHERE id = ?', 25.5, ['query_id' => 'q123']);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Query: SELECT * FROM users WHERE id = ? (25.5ms)', $breadcrumbs[0]['message']);
        $this->assertEquals('database', $breadcrumbs[0]['category']);
        $this->assertEquals('info', $breadcrumbs[0]['level']);
        $this->assertEquals(['query_id' => 'q123'], $breadcrumbs[0]['data']);
    }

    public function testLogQueryWithoutDuration()
    {
        BreadcrumbManager::logQuery('SELECT * FROM users', null, []);

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Query: SELECT * FROM users', $breadcrumbs[0]['message']);
        $this->assertEquals('database', $breadcrumbs[0]['category']);
    }

    public function testSetMaxBreadcrumbs()
    {
        BreadcrumbManager::setMaxBreadcrumbs(2);

        BreadcrumbManager::addBreadcrumb('Message 1');
        BreadcrumbManager::addBreadcrumb('Message 2');

        $this->assertCount(2, BreadcrumbManager::getBreadcrumbs());

        BreadcrumbManager::addBreadcrumb('Message 3');

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();
        $this->assertCount(2, $breadcrumbs);
        $this->assertEquals('Message 2', $breadcrumbs[0]['message']);
        $this->assertEquals('Message 3', $breadcrumbs[1]['message']);

        // Reset to default
        BreadcrumbManager::setMaxBreadcrumbs(50);
    }

    public function testBreadcrumbsAreStaticAcrossInstances()
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
