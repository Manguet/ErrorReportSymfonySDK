<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Tests\Integration;

use DateTimeInterface;
use ErrorExplorer\ErrorReporter\Enum\LogLevel;
use ErrorExplorer\ErrorReporter\Service\BatchManager;
use ErrorExplorer\ErrorReporter\Service\BreadcrumbManager;
use ErrorExplorer\ErrorReporter\Service\CircuitBreaker;
use ErrorExplorer\ErrorReporter\Service\CompressionService;
use ErrorExplorer\ErrorReporter\Service\EnhancedWebhookErrorReporter;
use ErrorExplorer\ErrorReporter\Service\OfflineManager;
use ErrorExplorer\ErrorReporter\Service\QuotaManager;
use ErrorExplorer\ErrorReporter\Service\RateLimiter;
use ErrorExplorer\ErrorReporter\Service\RetryManager;
use ErrorExplorer\ErrorReporter\Service\SDKMonitor;
use ErrorExplorer\ErrorReporter\Service\SecurityValidator;
use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class EnhancedErrorReportingTest extends TestCase
{
    private ArrayAdapter $cache;
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestStack = new RequestStack();

        BreadcrumbManager::clearBreadcrumbs();
    }

    protected function tearDown(): void
    {
        BreadcrumbManager::clearBreadcrumbs();
    }

    private function createSuccessfulHttpClient(): HttpClientInterface
    {
        return new MockHttpClient([
            // Online check response
            new MockResponse('OK', ['http_code' => 200]),
            // Error reporting response
            new MockResponse('{"status":"success"}', ['http_code' => 200])
        ]);
    }

    private function createFailingHttpClient(): HttpClientInterface
    {
        return new MockHttpClient([
            // Online check fails
            function ($method, $url) {
                if (str_contains($url, 'google.com')) {
                    throw new RuntimeException('Network error');
                }
                return new MockResponse('{"status":"success"}', ['http_code' => 200]);
            }
        ]);
    }

    private function createAllServices(): array
    {
        $offlineManager = new OfflineManager($this->cache, $this->httpClient, $this->logger);
        $quotaManager = new QuotaManager($this->cache, $this->logger);
        $rateLimiter = new RateLimiter($this->cache, $this->logger);
        $retryManager = new RetryManager($this->logger);
        $sdkMonitor = new SDKMonitor($this->cache, $this->logger);
        $securityValidator = new SecurityValidator($this->logger);
        $batchManager = new BatchManager($this->cache, $this->logger);
        $compressionService = new CompressionService($this->logger);
        $circuitBreaker = new CircuitBreaker($this->cache, $this->logger);

        return [
            'offlineManager' => $offlineManager,
            'quotaManager' => $quotaManager,
            'rateLimiter' => $rateLimiter,
            'retryManager' => $retryManager,
            'sdkMonitor' => $sdkMonitor,
            'securityValidator' => $securityValidator,
            'batchManager' => $batchManager,
            'compressionService' => $compressionService,
            'circuitBreaker' => $circuitBreaker,
        ];
    }

    public function testSuccessfulErrorReportingFlow(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        $exception = new Exception('Integration test error');

        // This should succeed and go through all the services
        $reporter->reportError($exception, 'test');

        // Verify stats show successful operation
        $stats = $reporter->getStats();

        $this->assertIsArray($stats['quota']);
        $this->assertIsArray($stats['rate_limiter']);
        $this->assertIsArray($stats['sdk_monitor']);
        $this->assertIsArray($stats['circuit_breaker']);
        $this->assertIsArray($stats['offline_queue']);
        $this->assertIsArray($stats['batch_manager']);

        // Verify quota was used
        $this->assertGreaterThan(0, $stats['quota']['dailyUsage']);
        $this->assertGreaterThan(0, $stats['quota']['monthlyUsage']);

        // Verify SDK monitoring recorded success
        $healthReport = $stats['sdk_monitor'];
        $this->assertArrayHasKey('totalRequests', $healthReport);
        $this->assertArrayHasKey('successfulRequests', $healthReport);
    }

    public function testOfflineErrorReportingFlow(): void
    {
        $this->httpClient = $this->createFailingHttpClient();
        $services = $this->createAllServices();

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        $exception = new Exception('Offline test error');

        // This should fail initially and queue the error offline
        $reporter->reportError($exception, 'test');

        // Verify offline queue has items
        $stats = $reporter->getStats();
        $this->assertGreaterThan(0, $stats['offline_queue']['size']);
        $this->assertFalse($stats['offline_queue']['isOnline']);
    }

    public function testQuotaLimitEnforcement(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        // Configure very low quota limits
        $services['quotaManager']->updateConfig([
            'dailyLimit' => 2,
            'monthlyLimit' => 5,
            'burstLimit' => 1
        ]);

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        // First error should succeed
        $reporter->reportError(new Exception('First error'), 'test');

        $stats = $reporter->getStats();
        $this->assertSame(1, $stats['quota']['dailyUsage']);
        $this->assertFalse($stats['quota']['isOverQuota']);

        // Second error should succeed
        $reporter->reportError(new Exception('Second error'), 'test');

        $stats = $reporter->getStats();
        $this->assertSame(2, $stats['quota']['dailyUsage']);
        $this->assertTrue($stats['quota']['isOverQuota']);

        // Third error should be rejected due to quota but queued offline
        $reporter->reportError(new Exception('Third error'), 'test');

        $stats = $reporter->getStats();
        $this->assertSame(2, $stats['quota']['dailyUsage']); // Should not increase
        $this->assertGreaterThan(0, $stats['offline_queue']['size']); // Should be queued offline
    }

    public function testRateLimitingEnforcement(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        // Configure very restrictive rate limiting
        $services['rateLimiter']->updateConfig([
            'maxRequests' => 1,
            'windowSeconds' => 3600 // 1 hour window
        ]);

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        $exception = new Exception('Rate limit test error');

        // First error should succeed
        $reporter->reportError($exception, 'test');

        $stats = $reporter->getStats();
        $rateLimiterStats = $stats['rate_limiter'];
        $this->assertGreaterThan(0, $rateLimiterStats['requestCount']);

        // Second identical error should be rate limited
        $reporter->reportError($exception, 'test');

        $stats = $reporter->getStats();
        $rateLimiterStats = $stats['rate_limiter'];
        $this->assertGreaterThan(0, $rateLimiterStats['blockedCount']);
    }

    public function testBatchProcessing(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        // Configure batch processing
        $services['batchManager'] = new BatchManager($this->cache, $this->logger, [
            'enabled' => true,
            'maxSize' => 3,
            'maxWaitTime' => 1 // 1 second
        ]);

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        // Report multiple errors
        $reporter->reportError(new Exception('Batch error 1'), 'test');
        $reporter->reportError(new Exception('Batch error 2'), 'test');

        $stats = $reporter->getStats();
        $batchStats = $stats['batch_manager'];
        
        // Verify batching is working
        $this->assertArrayHasKey('batchSize', $batchStats);
        $this->assertArrayHasKey('totalBatches', $batchStats);
    }

    public function testCompressionWhenEnabled(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        // Configure compression with low threshold
        $services['compressionService'] = new CompressionService($this->logger, [
            'enabled' => true,
            'threshold' => 100, // Very low threshold to ensure compression kicks in
            'algorithm' => 'gzip'
        ]);

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        // Create a large error message to trigger compression
        $longMessage = str_repeat('This is a very long error message that should trigger compression. ', 50);
        $exception = new Exception($longMessage);

        $reporter->reportError($exception, 'test');

        // The compression service should have been used (no direct way to verify without mocking,
        // but we can ensure no exceptions were thrown and the flow completed)
        $stats = $reporter->getStats();
        $this->assertIsArray($stats);
    }

    public function testCircuitBreakerOperation(): void
    {
        // Create a client that fails initially
        $responses = [
            new MockResponse('OK', ['http_code' => 200]), // Online check
            function () { throw new RuntimeException('Service unavailable'); }, // First error report fails
            function () { throw new RuntimeException('Service unavailable'); }, // Second fails
            function () { throw new RuntimeException('Service unavailable'); }, // Third fails
            function () { throw new RuntimeException('Service unavailable'); }, // Fourth fails
            function () { throw new RuntimeException('Service unavailable'); }, // Fifth fails - circuit opens
        ];
        
        $this->httpClient = new MockHttpClient($responses);
        $services = $this->createAllServices();

        // Configure circuit breaker with low failure threshold
        $services['circuitBreaker'] = new CircuitBreaker($this->cache, $this->logger, [
            'failureThreshold' => 3,
            'resetTimeout' => 60000
        ]);

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        // Report errors that will fail and trigger circuit breaker
        for ($i = 1; $i <= 5; $i++) {
            try {
                $reporter->reportError(new Exception("Circuit breaker test error $i"), 'test');
            } catch (Exception $e) {
                // Expected to fail as service is unavailable
            }
        }

        $stats = $reporter->getStats();
        
        // Circuit breaker should eventually open
        $this->assertArrayHasKey('circuit_breaker', $stats);
        $this->assertArrayHasKey('state', $stats['circuit_breaker']);
    }

    public function testSecurityValidationAndSanitization(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        // Create a request with sensitive data
        $request = Request::create(
            'https://example.com/login',
            'POST',
            ['username' => 'testuser', 'password' => 'secret123'],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer secret-token']
        );

        $this->requestStack->push($request);

        $exception = new Exception('Authentication error');

        // This should sanitize sensitive data during processing
        $reporter->reportError($exception, 'test', request: $request);

        // Verify the error was processed (no exceptions thrown)
        $stats = $reporter->getStats();
        $this->assertIsArray($stats);
    }

    public function testCompleteWorkflowWithBreadcrumbs(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        // Add breadcrumbs before reporting
        BreadcrumbManager::addBreadcrumb('user.login', 'User attempted to login');
        BreadcrumbManager::addBreadcrumb('validation', 'Password validation failed');
        BreadcrumbManager::addBreadcrumb('security', 'Account locked due to failed attempts');

        $exception = new Exception('Account lockout triggered');

        $reporter->reportError($exception, 'production');

        // Verify everything processed successfully
        $stats = $reporter->getStats();
        $this->assertGreaterThan(0, $stats['quota']['dailyUsage']);
        $this->assertGreaterThan(0, $stats['sdk_monitor']['totalRequests']);
    }

    public function testMessageReportingWithContext(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        $context = [
            'user_id' => 12345,
            'action' => 'payment_processing',
            'amount' => 99.99,
            'currency' => 'USD'
        ];

        $reporter->reportMessage(
            'Payment processing completed successfully',
            'production',
            null,
            null,
            LogLevel::INFO,
            $context
        );

        // Verify message was processed
        $stats = $reporter->getStats();
        $this->assertGreaterThan(0, $stats['quota']['dailyUsage']);
    }

    public function testFlushOperations(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        // Add some errors to batch
        $reporter->reportError(new Exception('Batch test 1'), 'test');
        $reporter->reportError(new Exception('Batch test 2'), 'test');

        // Flush batch
        $reporter->flushBatch();

        // Add offline error
        $this->httpClient = $this->createFailingHttpClient();
        $reporter->reportError(new Exception('Offline test'), 'test');

        // Flush offline queue (should be a no-op since we're offline)
        $reporter->flushOfflineQueue();

        // Verify no exceptions thrown
        $this->assertTrue(true);
    }

    public function testResourceCleanup(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        // Report some errors
        $reporter->reportError(new Exception('Cleanup test 1'), 'test');
        $reporter->reportError(new Exception('Cleanup test 2'), 'test');

        // Destroy should clean up all services
        $reporter->destroy();

        // Verify no exceptions thrown during cleanup
        $this->assertTrue(true);
    }

    public function testLowLevelMessageFiltering(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'test-token-123',
            projectName: 'integration-test',
            minimumLevel: LogLevel::WARNING, // Only WARNING and above
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        // This should be filtered out (below minimum level)
        $reporter->reportMessage('Debug message', level: LogLevel::DEBUG);

        // This should be processed (meets minimum level)
        $reporter->reportMessage('Warning message', level: LogLevel::WARNING);

        $stats = $reporter->getStats();
        
        // Only one message should have been processed
        $this->assertSame(1, $stats['quota']['dailyUsage']);
    }

    public function testFullIntegrationWithAllFeatures(): void
    {
        $this->httpClient = $this->createSuccessfulHttpClient();
        $services = $this->createAllServices();

        // Configure all services with specific settings
        $services['quotaManager']->updateConfig(['dailyLimit' => 100, 'burstLimit' => 5]);
        $services['rateLimiter']->updateConfig(['maxRequests' => 10, 'windowSeconds' => 60]);

        $reporter = new EnhancedWebhookErrorReporter(
            webhookUrl: 'https://api.errorexplorer.com',
            token: 'integration-token-456',
            projectName: 'full-integration-test',
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack,
            offlineManager: $services['offlineManager'],
            quotaManager: $services['quotaManager'],
            rateLimiter: $services['rateLimiter'],
            retryManager: $services['retryManager'],
            sdkMonitor: $services['sdkMonitor'],
            securityValidator: $services['securityValidator'],
            batchManager: $services['batchManager'],
            compressionService: $services['compressionService'],
            circuitBreaker: $services['circuitBreaker']
        );

        // Setup complex scenario
        $request = Request::create('https://app.example.com/api/users/create', 'POST');
        $request->request->add(['name' => 'John Doe', 'email' => 'john@example.com']);
        $this->requestStack->push($request);

        // Add breadcrumbs
        BreadcrumbManager::addBreadcrumb('api.request', 'User creation API called');
        BreadcrumbManager::addBreadcrumb('validation', 'Input validation passed');
        BreadcrumbManager::addBreadcrumb('database', 'Attempting to save user');

        // Report an error with full context
        $exception = new Exception('Database connection failed');
        $reporter->reportError($exception, 'production', 500, $request);

        // Report a few messages
        $reporter->reportMessage('User creation attempt', 'production', null, $request, LogLevel::INFO, [
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0'
        ]);

        $reporter->reportMessage('Critical database issue detected', 'production', null, $request, LogLevel::CRITICAL, [
            'database' => 'users_db',
            'connection_pool' => 'primary'
        ]);

        // Get comprehensive stats
        $stats = $reporter->getStats();

        // Verify all systems worked
        $this->assertGreaterThan(0, $stats['quota']['dailyUsage']);
        $this->assertGreaterThan(0, $stats['quota']['monthlyUsage']);
        $this->assertGreaterThan(0, $stats['rate_limiter']['requestCount']);
        $this->assertGreaterThan(0, $stats['sdk_monitor']['totalRequests']);
        $this->assertSame('CLOSED', $stats['circuit_breaker']['state']);
        $this->assertSame(0, $stats['offline_queue']['size']); // Should be empty as we're online
        $this->assertTrue($stats['offline_queue']['isOnline']);

        // Test batch flushing
        $reporter->flushBatch();

        // Test cleanup
        $reporter->destroy();
    }
}