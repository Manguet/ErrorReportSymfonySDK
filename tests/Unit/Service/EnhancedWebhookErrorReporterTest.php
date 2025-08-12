<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Tests\Unit\Service;

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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

#[CoversClass(EnhancedWebhookErrorReporter::class)]
final class EnhancedWebhookErrorReporterTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;
    private RequestStack&MockObject $requestStack;
    private OfflineManager&MockObject $offlineManager;
    private QuotaManager&MockObject $quotaManager;
    private RateLimiter&MockObject $rateLimiter;
    private RetryManager&MockObject $retryManager;
    private SDKMonitor&MockObject $sdkMonitor;
    private SecurityValidator&MockObject $securityValidator;
    private BatchManager&MockObject $batchManager;
    private CompressionService&MockObject $compressionService;
    private CircuitBreaker&MockObject $circuitBreaker;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->offlineManager = $this->createMock(OfflineManager::class);
        $this->quotaManager = $this->createMock(QuotaManager::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
        $this->retryManager = $this->createMock(RetryManager::class);
        $this->sdkMonitor = $this->createMock(SDKMonitor::class);
        $this->securityValidator = $this->createMock(SecurityValidator::class);
        $this->batchManager = $this->createMock(BatchManager::class);
        $this->compressionService = $this->createMock(CompressionService::class);
        $this->circuitBreaker = $this->createMock(CircuitBreaker::class);

        BreadcrumbManager::clearBreadcrumbs();
    }

    protected function tearDown(): void
    {
        BreadcrumbManager::clearBreadcrumbs();
    }

    private function createReporter(array $overrides = []): EnhancedWebhookErrorReporter
    {
        return new EnhancedWebhookErrorReporter(
            webhookUrl: $overrides['webhookUrl'] ?? 'https://example.com',
            token: $overrides['token'] ?? 'test-token',
            projectName: $overrides['projectName'] ?? 'test-project',
            enabled: $overrides['enabled'] ?? true,
            ignoredExceptions: $overrides['ignoredExceptions'] ?? [],
            timeout: $overrides['timeout'] ?? 5,
            maxRetries: $overrides['maxRetries'] ?? 3,
            minimumLevel: $overrides['minimumLevel'] ?? LogLevel::ERROR,
            httpClient: $overrides['httpClient'] ?? $this->httpClient,
            logger: $overrides['logger'] ?? $this->logger,
            requestStack: $overrides['requestStack'] ?? $this->requestStack,
            offlineManager: $overrides['offlineManager'] ?? $this->offlineManager,
            quotaManager: $overrides['quotaManager'] ?? $this->quotaManager,
            rateLimiter: $overrides['rateLimiter'] ?? $this->rateLimiter,
            retryManager: $overrides['retryManager'] ?? $this->retryManager,
            sdkMonitor: $overrides['sdkMonitor'] ?? $this->sdkMonitor,
            securityValidator: $overrides['securityValidator'] ?? $this->securityValidator,
            batchManager: $overrides['batchManager'] ?? $this->batchManager,
            compressionService: $overrides['compressionService'] ?? $this->compressionService,
            circuitBreaker: $overrides['circuitBreaker'] ?? $this->circuitBreaker,
            config: $overrides['config'] ?? []
        );
    }

    public function testReportErrorWhenDisabled(): void
    {
        $reporter = $this->createReporter(['enabled' => false]);
        $exception = new Exception('Test error');

        // Should not call any services
        $this->sdkMonitor->expects($this->never())->method('startOperation');
        $this->httpClient->expects($this->never())->method('request');

        $reporter->reportError($exception);
    }

    public function testReportErrorWithIgnoredException(): void
    {
        $reporter = $this->createReporter(['ignoredExceptions' => [RuntimeException::class]]);
        $exception = new RuntimeException('Test error');

        // Should not call any services
        $this->sdkMonitor->expects($this->never())->method('startOperation');
        $this->httpClient->expects($this->never())->method('request');

        $reporter->reportError($exception);
    }

    public function testReportErrorSuccessfulFlow(): void
    {
        $reporter = $this->createReporter();
        $exception = new Exception('Test error');

        // Setup mocks for successful flow
        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordRequest');
        $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(false);
        $this->batchManager->expects($this->once())->method('addToBatch');
        $this->quotaManager->expects($this->once())->method('recordUsage');
        $this->sdkMonitor->expects($this->once())->method('recordSuccess');
        $this->sdkMonitor->expects($this->once())->method('endOperation')->with('error_report');

        $reporter->reportError($exception);
    }

    public function testReportErrorWithSecurityValidationFailure(): void
    {
        $reporter = $this->createReporter();
        $exception = new Exception('Test error');

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(false);
        $this->sdkMonitor->expects($this->once())->method('recordError')->with('validation_failed');

        // Should not proceed further
        $this->quotaManager->expects($this->never())->method('canSendError');
        $this->httpClient->expects($this->never())->method('request');

        $reporter->reportError($exception);
    }

    public function testReportErrorWithQuotaExceeded(): void
    {
        $reporter = $this->createReporter();
        $exception = new Exception('Test error');

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn([
            'allowed' => false,
            'reason' => 'Daily quota exceeded'
        ]);
        $this->sdkMonitor->expects($this->once())->method('recordError')->with('quota_exceeded');
        $this->offlineManager->expects($this->once())->method('handleError');

        // Should not send directly
        $this->httpClient->expects($this->never())->method('request');

        $reporter->reportError($exception);
    }

    public function testReportErrorWithRateLimitExceeded(): void
    {
        $reporter = $this->createReporter();
        $exception = new Exception('Test error');

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(false);
        $this->sdkMonitor->expects($this->once())->method('recordError')->with('rate_limited');

        // Should not proceed further
        $this->httpClient->expects($this->never())->method('request');

        $reporter->reportError($exception);
    }

    public function testReportErrorWithCompressionEnabled(): void
    {
        $reporter = $this->createReporter();
        $exception = new Exception('Test error');

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordRequest');
        $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(true);
        $this->compressionService->expects($this->once())->method('compress')->willReturn('compressed-data');

        $this->batchManager->expects($this->once())->method('addToBatch')
            ->with($this->callback(function ($payload) {
                return isset($payload['compressed']) && $payload['compressed'] === true;
            }));

        $reporter->reportError($exception);
    }

    public function testReportErrorWithDirectSending(): void
    {
        $reporter = $this->createReporter(['batchManager' => null]);
        $exception = new Exception('Test error');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordRequest');
        $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(false);

        $this->circuitBreaker->expects($this->once())->method('execute')
            ->willReturnCallback(fn($callback) => $callback());
        $this->retryManager->expects($this->once())->method('executeWithRetry')
            ->willReturnCallback(fn($callback) => $callback());
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        $reporter->reportError($exception);
    }

    public function testReportErrorWithInternalException(): void
    {
        $reporter = $this->createReporter();
        $exception = new Exception('Test error');

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')
            ->willThrowException(new RuntimeException('Security validation failed'));

        $this->sdkMonitor->expects($this->once())->method('recordError')->with('internal_error');
        $this->logger->expects($this->once())->method('error');
        $this->offlineManager->expects($this->once())->method('handleError');

        $reporter->reportError($exception);
    }

    #[DataProvider('logLevelProvider')]
    public function testReportMessage(LogLevel $level, bool $shouldProcess): void
    {
        $reporter = $this->createReporter(['minimumLevel' => LogLevel::WARNING]);
        $message = 'Test message';

        if ($shouldProcess) {
            $this->sdkMonitor->expects($this->once())->method('startOperation')->with('message_report');
            $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
            $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
            $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
            $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
            $this->rateLimiter->expects($this->once())->method('recordRequest');
            $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(false);
            $this->batchManager->expects($this->once())->method('addToBatch');
            $this->quotaManager->expects($this->once())->method('recordUsage');
            $this->sdkMonitor->expects($this->once())->method('recordSuccess');
            $this->sdkMonitor->expects($this->once())->method('endOperation')->with('message_report');
        } else {
            $this->sdkMonitor->expects($this->never())->method('startOperation');
        }

        $reporter->reportMessage($message, level: $level);
    }

    public static function logLevelProvider(): array
    {
        return [
            'debug below minimum' => [LogLevel::DEBUG, false],
            'info below minimum' => [LogLevel::INFO, false],
            'warning meets minimum' => [LogLevel::WARNING, true],
            'error above minimum' => [LogLevel::ERROR, true],
            'critical above minimum' => [LogLevel::CRITICAL, true],
        ];
    }

    public function testReportMessageWithStringLevel(): void
    {
        $reporter = $this->createReporter();
        $message = 'Test message';

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('message_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordRequest');
        $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(false);
        $this->batchManager->expects($this->once())->method('addToBatch');

        $reporter->reportMessage($message, level: 'error');
    }

    public function testReportMessageWithContext(): void
    {
        $reporter = $this->createReporter();
        $message = 'Test message';
        $context = ['user_id' => 123, 'action' => 'login'];

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('message_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordRequest');
        $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(false);

        $this->batchManager->expects($this->once())->method('addToBatch')
            ->with($this->callback(function ($payload) use ($context) {
                return isset($payload['context']) && $payload['context'] === $context;
            }));

        $reporter->reportMessage($message, context: $context);
    }

    public function testReportErrorWithRequest(): void
    {
        $reporter = $this->createReporter();
        $exception = new Exception('Test error');

        // Mock request
        $request = $this->createMock(Request::class);
        $request->method('getUri')->willReturn('https://example.com/test');
        $request->method('getMethod')->willReturn('POST');
        $request->method('getClientIp')->willReturn('127.0.0.1');

        $requestHeaders = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $requestHeaders->method('get')->willReturnMap([
            ['User-Agent', null, 'Test User Agent'],
        ]);
        $requestHeaders->method('all')->willReturn(['User-Agent' => ['Test User Agent']]);
        $request->headers = $requestHeaders;

        $requestParams = $this->createMock(\Symfony\Component\HttpFoundation\ParameterBag::class);
        $requestParams->method('all')->willReturn(['param' => 'value']);
        $request->request = $requestParams;
        $request->query = $requestParams;

        $requestAttributes = $this->createMock(\Symfony\Component\HttpFoundation\ParameterBag::class);
        $requestAttributes->method('get')->with('_route')->willReturn('test_route');
        $request->attributes = $requestAttributes;

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordRequest');
        $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(false);

        $this->batchManager->expects($this->once())->method('addToBatch')
            ->with($this->callback(function ($payload) {
                return isset($payload['request']['url']) && $payload['request']['url'] === 'https://example.com/test';
            }));

        $reporter->reportError($exception, request: $request);
    }

    public function testReportErrorWithBreadcrumbs(): void
    {
        $reporter = $this->createReporter();
        $exception = new Exception('Test error');

        // Add some breadcrumbs
        BreadcrumbManager::addBreadcrumb('test', 'Test breadcrumb');

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordRequest');
        $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(false);

        $this->batchManager->expects($this->once())->method('addToBatch')
            ->with($this->callback(function ($payload) {
                return isset($payload['breadcrumbs']) && is_array($payload['breadcrumbs']);
            }));

        $reporter->reportError($exception);
    }

    public function testGetStats(): void
    {
        $reporter = $this->createReporter();

        $expectedQuotaStats = ['dailyUsage' => 10, 'monthlyUsage' => 100];
        $expectedRateLimiterStats = ['requestCount' => 5, 'blockedCount' => 2];
        $expectedSdkMonitorStats = ['uptime' => 1000, 'errors' => 5];
        $expectedCircuitBreakerState = 'CLOSED';
        $expectedOfflineQueueStats = ['size' => 3, 'oldestTimestamp' => 1234567890];
        $expectedBatchManagerStats = ['batchSize' => 2, 'totalBatches' => 10];

        $this->quotaManager->expects($this->once())->method('getStats')->willReturn($expectedQuotaStats);
        $this->rateLimiter->expects($this->once())->method('getStats')->willReturn($expectedRateLimiterStats);
        $this->sdkMonitor->expects($this->once())->method('getHealthReport')->willReturn($expectedSdkMonitorStats);
        $this->circuitBreaker->expects($this->once())->method('getState')->willReturn($expectedCircuitBreakerState);
        $this->offlineManager->expects($this->once())->method('getQueueStats')->willReturn($expectedOfflineQueueStats);
        $this->batchManager->expects($this->once())->method('getStats')->willReturn($expectedBatchManagerStats);

        $stats = $reporter->getStats();

        $this->assertSame($expectedQuotaStats, $stats['quota']);
        $this->assertSame($expectedRateLimiterStats, $stats['rate_limiter']);
        $this->assertSame($expectedSdkMonitorStats, $stats['sdk_monitor']);
        $this->assertSame(['state' => $expectedCircuitBreakerState], $stats['circuit_breaker']);
        $this->assertSame($expectedOfflineQueueStats, $stats['offline_queue']);
        $this->assertSame($expectedBatchManagerStats, $stats['batch_manager']);
    }

    public function testFlushBatch(): void
    {
        $reporter = $this->createReporter();

        $this->batchManager->expects($this->once())->method('flush');

        $reporter->flushBatch();
    }

    public function testFlushOfflineQueue(): void
    {
        $reporter = $this->createReporter();

        $this->offlineManager->expects($this->once())->method('flushQueue');

        $reporter->flushOfflineQueue();
    }

    public function testSendData(): void
    {
        $reporter = $this->createReporter(['batchManager' => null, 'circuitBreaker' => null, 'retryManager' => null]);
        $data = ['message' => 'test', 'project' => 'test-project'];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->once())->method('request')
            ->with('POST', 'https://example.com/webhook/error/test-token', [
                'json' => $data,
                'timeout' => 5,
                'headers' => $this->callback(function ($headers) {
                    return isset($headers['Content-Type']) &&
                           $headers['Content-Type'] === 'application/json' &&
                           isset($headers['User-Agent']) &&
                           str_contains($headers['User-Agent'], 'ErrorExplorer-SDK/2.0-modern-enhanced');
                }),
            ])
            ->willReturn($response);

        $reporter->sendData($data);
    }

    public function testDestroy(): void
    {
        $reporter = $this->createReporter();

        $this->offlineManager->expects($this->once())->method('destroy');
        $this->quotaManager->expects($this->once())->method('destroy');
        $this->rateLimiter->expects($this->once())->method('destroy');
        $this->batchManager->expects($this->once())->method('destroy');
        $this->circuitBreaker->expects($this->once())->method('reset');

        $reporter->destroy();
    }

    public function testPayloadStructureForException(): void
    {
        $reporter = $this->createReporter();
        $exception = new Exception('Test error message');

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordRequest');
        $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(false);

        $this->batchManager->expects($this->once())->method('addToBatch')
            ->with($this->callback(function ($payload) {
                $requiredFields = [
                    'message', 'exception_class', 'stack_trace', 'file', 'line',
                    'project', 'environment', 'timestamp', 'fingerprint', 'level', 'sdk_version'
                ];

                foreach ($requiredFields as $field) {
                    if (!isset($payload[$field])) {
                        return false;
                    }
                }

                return $payload['message'] === 'Test error message' &&
                       $payload['exception_class'] === Exception::class &&
                       $payload['project'] === 'test-project' &&
                       $payload['environment'] === 'prod' &&
                       $payload['level'] === LogLevel::ERROR->value &&
                       $payload['sdk_version'] === '2.0-modern' &&
                       isset($payload['server']);
            }));

        $reporter->reportError($exception);
    }

    public function testPayloadStructureForMessage(): void
    {
        $reporter = $this->createReporter();
        $message = 'Test message';
        $context = ['key' => 'value'];

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('message_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordRequest');
        $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(false);

        $this->batchManager->expects($this->once())->method('addToBatch')
            ->with($this->callback(function ($payload) use ($message, $context) {
                return $payload['message'] === $message &&
                       $payload['exception_class'] === 'CustomMessage' &&
                       $payload['context'] === $context &&
                       $payload['level'] === LogLevel::ERROR->value &&
                       $payload['sdk_version'] === '2.0-modern' &&
                       isset($payload['server']) &&
                       isset($payload['stack_trace']);
            }));

        $reporter->reportMessage($message, context: $context);
    }

    public function testSensitiveDataSanitization(): void
    {
        $reporter = $this->createReporter();
        $exception = new Exception('Test error');

        // Mock request with sensitive data
        $request = $this->createMock(Request::class);
        $request->method('getUri')->willReturn('https://example.com/test');
        $request->method('getMethod')->willReturn('POST');
        $request->method('getClientIp')->willReturn('127.0.0.1');

        $requestHeaders = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $requestHeaders->method('get')->willReturnMap([
            ['User-Agent', null, 'Test User Agent'],
        ]);
        $requestHeaders->method('all')->willReturn([
            'Authorization' => ['Bearer secret-token'],
            'User-Agent' => ['Test User Agent'],
        ]);
        $request->headers = $requestHeaders;

        $requestParams = $this->createMock(\Symfony\Component\HttpFoundation\ParameterBag::class);
        $requestParams->method('all')->willReturn([
            'password' => 'secret',
            'username' => 'testuser',
        ]);
        $request->request = $requestParams;
        $request->query = $requestParams;

        $requestAttributes = $this->createMock(\Symfony\Component\HttpFoundation\ParameterBag::class);
        $requestAttributes->method('get')->with('_route')->willReturn('test_route');
        $request->attributes = $requestAttributes;

        $this->sdkMonitor->expects($this->once())->method('startOperation')->with('error_report');
        $this->securityValidator->expects($this->once())->method('validateErrorData')->willReturn(true);
        $this->securityValidator->expects($this->once())->method('sanitizeSensitiveData')->willReturnArgument(0);
        $this->quotaManager->expects($this->once())->method('canSendError')->willReturn(['allowed' => true]);
        $this->rateLimiter->expects($this->once())->method('isAllowed')->willReturn(true);
        $this->rateLimiter->expects($this->once())->method('recordRequest');
        $this->compressionService->expects($this->once())->method('shouldCompress')->willReturn(false);

        $this->batchManager->expects($this->once())->method('addToBatch')
            ->with($this->callback(function ($payload) {
                // Check that sensitive data is redacted
                return isset($payload['request']['headers']['authorization']) &&
                       $payload['request']['headers']['authorization'] === '[REDACTED]' &&
                       isset($payload['request']['parameters']['password']) &&
                       $payload['request']['parameters']['password'] === '[REDACTED]';
            }));

        $reporter->reportError($exception, request: $request);
    }
}