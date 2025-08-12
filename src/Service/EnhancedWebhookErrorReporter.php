<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use DateTimeInterface;
use DateTimeImmutable;
use ErrorExplorer\ErrorReporter\Enum\LogLevel;
use ErrorExplorer\ErrorReporter\ValueObject\ErrorFingerprint;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Enhanced WebhookErrorReporter with all modern services integrated
 */
class EnhancedWebhookErrorReporter
{
    private const SENSITIVE_KEYS = ['password', 'token', 'secret', 'key', 'api_key', 'authorization'];
    private const SENSITIVE_HEADERS = ['authorization', 'cookie', 'x-api-key', 'x-auth-token'];

    public function __construct(
        private readonly string $webhookUrl,
        private readonly string $token,
        private readonly string $projectName,
        private readonly bool $enabled = true,
        private readonly array $ignoredExceptions = [],
        private readonly int $timeout = 5,
        private readonly int $maxRetries = 3,
        private readonly LogLevel $minimumLevel = LogLevel::ERROR,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?OfflineManager $offlineManager = null,
        private readonly ?QuotaManager $quotaManager = null,
        private readonly ?RateLimiter $rateLimiter = null,
        private readonly ?RetryManager $retryManager = null,
        private readonly ?SDKMonitor $sdkMonitor = null,
        private readonly ?SecurityValidator $securityValidator = null,
        private readonly ?BatchManager $batchManager = null,
        private readonly ?CompressionService $compressionService = null,
        private readonly ?CircuitBreaker $circuitBreaker = null,
        private readonly array $config = [],
    )
    {
        // Initialize batch manager callback if present
        if ($this->batchManager) {
            $this->batchManager->setSendFunction([$this, 'sendData']);
        }
        
        // Initialize offline manager callback if present  
        if ($this->offlineManager) {
            $this->offlineManager->setSendFunction([$this, 'sendData']);
        }
    }

    private function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient ?? HttpClient::create();
    }

    public function reportError(
        Throwable $exception,
        string    $environment = 'prod',
        ?int      $httpStatus = null,
        ?Request  $request = null
    ): void
    {
        if (!$this->enabled || $this->shouldIgnoreException($exception)) {
            return;
        }

        try {
            // Start monitoring
            $this->sdkMonitor?->startOperation('error_report');

            // Build payload
            $payload = $this->buildPayload($exception, $environment, $httpStatus, $request);
            
            // Security validation
            if ($this->securityValidator && !$this->securityValidator->validateErrorData($payload)) {
                $this->sdkMonitor?->recordError('validation_failed');
                return;
            }
            
            // Sanitize sensitive data
            if ($this->securityValidator) {
                $payload = $this->securityValidator->sanitizeSensitiveData($payload);
            }
            
            // Check quotas
            $payloadSize = strlen(json_encode($payload));
            if ($this->quotaManager) {
                $quotaResult = $this->quotaManager->canSendError($payloadSize);
                if (!$quotaResult['allowed']) {
                    $this->sdkMonitor?->recordError('quota_exceeded');
                    // Try offline queue
                    if ($this->offlineManager) {
                        $this->offlineManager->handleError($payload);
                    }
                    return;
                }
            }
            
            // Check rate limiting
            $errorHash = $this->generateErrorHash($exception);
            if ($this->rateLimiter && !$this->rateLimiter->isAllowed($errorHash)) {
                $this->sdkMonitor?->recordError('rate_limited');
                return;
            }
            
            // Record rate limit usage
            $this->rateLimiter?->recordRequest($errorHash);
            
            // Compress if needed
            if ($this->compressionService && $this->compressionService->shouldCompress($payloadSize)) {
                $payload['compressed'] = true;
                $payload['payload'] = $this->compressionService->compress(json_encode($payload));
            }
            
            // Send via batch or direct
            if ($this->batchManager) {
                $this->batchManager->addToBatch($payload);
            } else {
                $this->sendWithCircuitBreaker($payload);
            }
            
            // Record success
            $this->quotaManager?->recordUsage($payloadSize);
            $this->sdkMonitor?->recordSuccess();
            $this->sdkMonitor?->endOperation('error_report');
            
        } catch (Throwable $e) {
            $this->sdkMonitor?->recordError('internal_error');
            $this->logger?->error('Failed to report error to Error Explorer', [
                'exception' => $e->getMessage(),
                'original_error' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);
            
            // Try offline queue as fallback
            if ($this->offlineManager) {
                $payload = $this->buildPayload($exception, $environment, $httpStatus, $request);
                $this->offlineManager->handleError($payload);
            }
        }
    }

    public function reportMessage(
        string          $message,
        string          $environment = 'prod',
        ?int            $httpStatus = null,
        ?Request        $request = null,
        LogLevel|string $level = LogLevel::ERROR,
        array           $context = []
    ): void
    {
        if (!$this->enabled) {
            return;
        }

        $logLevel = $level instanceof LogLevel ? $level : LogLevel::from($level);

        if ($logLevel->getPriority() < $this->minimumLevel->getPriority()) {
            return;
        }

        try {
            // Start monitoring
            $this->sdkMonitor?->startOperation('message_report');
            
            $payload = $this->buildMessagePayload($message, $environment, $httpStatus, $request, $logLevel, $context);
            
            // Security validation and processing (same as reportError)
            if ($this->securityValidator) {
                if (!$this->securityValidator->validateErrorData($payload)) {
                    $this->sdkMonitor?->recordError('validation_failed');
                    return;
                }
                $payload = $this->securityValidator->sanitizeSensitiveData($payload);
            }
            
            // Check quotas and rate limiting
            $payloadSize = strlen(json_encode($payload));
            if ($this->quotaManager) {
                $quotaResult = $this->quotaManager->canSendError($payloadSize);
                if (!$quotaResult['allowed']) {
                    $this->sdkMonitor?->recordError('quota_exceeded');
                    if ($this->offlineManager) {
                        $this->offlineManager->handleError($payload);
                    }
                    return;
                }
            }
            
            $messageHash = hash('sha256', $message . $logLevel->value);
            if ($this->rateLimiter && !$this->rateLimiter->isAllowed($messageHash)) {
                $this->sdkMonitor?->recordError('rate_limited');
                return;
            }
            
            $this->rateLimiter?->recordRequest($messageHash);
            
            // Compress and send
            if ($this->compressionService && $this->compressionService->shouldCompress($payloadSize)) {
                $payload['compressed'] = true;
                $payload['payload'] = $this->compressionService->compress(json_encode($payload));
            }
            
            if ($this->batchManager) {
                $this->batchManager->addToBatch($payload);
            } else {
                $this->sendWithCircuitBreaker($payload);
            }
            
            $this->quotaManager?->recordUsage($payloadSize);
            $this->sdkMonitor?->recordSuccess();
            $this->sdkMonitor?->endOperation('message_report');
            
        } catch (Throwable $e) {
            $this->sdkMonitor?->recordError('internal_error');
            $this->logger?->error('Failed to report message to Error Explorer', [
                'exception' => $e->getMessage(),
                'original_message' => $message,
            ]);
            
            // Try offline queue as fallback
            if ($this->offlineManager) {
                $payload = $this->buildMessagePayload($message, $environment, $httpStatus, $request, $logLevel, $context);
                $this->offlineManager->handleError($payload);
            }
        }
    }

    private function generateErrorHash(Throwable $exception): string
    {
        return hash('sha256', $exception->getMessage() . $exception->getFile() . $exception->getLine());
    }
    
    private function sendWithCircuitBreaker(array $payload): void
    {
        if ($this->circuitBreaker) {
            $this->circuitBreaker->execute(fn() => $this->sendViaRetryManager($payload));
        } else {
            $this->sendViaRetryManager($payload);
        }
    }
    
    private function sendViaRetryManager(array $payload): void
    {
        if ($this->retryManager) {
            $this->retryManager->executeWithRetry(fn() => $this->sendWebhook($payload));
        } else {
            $this->sendWebhook($payload);
        }
    }

    private function shouldIgnoreException(Throwable $exception): bool
    {
        $exceptionClass = $exception::class;

        return array_reduce(
            $this->ignoredExceptions,
            fn(bool $carry, string $ignoredException): bool => $carry || $exceptionClass === $ignoredException || is_subclass_of($exceptionClass, $ignoredException),
            false
        );
    }

    private function buildPayload(
        Throwable $exception,
        string    $environment,
        ?int      $httpStatus,
        ?Request  $request = null
    ): array
    {
        $request ??= $this->requestStack?->getCurrentRequest();
        $fingerprint = ErrorFingerprint::fromException($exception);

        $payload = [
            'message' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'stack_trace' => $exception->getTraceAsString(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'project' => $this->projectName,
            'environment' => $environment,
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'fingerprint' => $fingerprint->toString(),
            'level' => LogLevel::ERROR->value,
            'sdk_version' => '2.0-modern',
        ];

        if ($httpStatus !== null) {
            $payload['http_status'] = $httpStatus;
        }

        if ($request !== null) {
            $payload['request'] = $this->buildRequestContext($request);
        }

        $payload['server'] = $this->buildServerContext();

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();
        if ($breadcrumbs !== []) {
            $payload['breadcrumbs'] = $breadcrumbs;
        }

        return $payload;
    }

    private function buildMessagePayload(
        string   $message,
        string   $environment,
        ?int     $httpStatus,
        ?Request $request,
        LogLevel $level,
        array    $context
    ): array
    {
        $request ??= $this->requestStack?->getCurrentRequest();
        $fingerprint = ErrorFingerprint::fromMessage($message, $level->value);

        $payload = [
            'message' => $message,
            'exception_class' => 'CustomMessage',
            'stack_trace' => $this->generateStackTrace(),
            'file' => null,
            'line' => null,
            'project' => $this->projectName,
            'environment' => $environment,
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'fingerprint' => $fingerprint->toString(),
            'level' => $level->value,
            'context' => $context,
            'sdk_version' => '2.0-modern',
        ];

        if ($httpStatus !== null) {
            $payload['http_status'] = $httpStatus;
        }

        if ($request !== null) {
            $payload['request'] = $this->buildRequestContext($request);
        }

        $payload['server'] = $this->buildServerContext();

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();
        if ($breadcrumbs !== []) {
            $payload['breadcrumbs'] = $breadcrumbs;
        }

        return $payload;
    }

    private function buildRequestContext(Request $request): array
    {
        return [
            'url' => $request->getUri(),
            'method' => $request->getMethod(),
            'route' => $request->attributes->get('_route'),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'parameters' => $this->sanitizeParameters($request->request->all()),
            'query' => $this->sanitizeParameters($request->query->all()),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
        ];
    }

    private function buildServerContext(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(),
            'memory_peak' => memory_get_peak_usage(),
            'server_time' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }

    private function generateStackTrace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $trace = array_slice($trace, 3);

        return implode("\n", array_map(
            static fn(int $i, array $call): string => sprintf(
                '#%d %s(%d): %s()',
                $i,
                $call['file'] ?? '[internal]',
                $call['line'] ?? 0,
                isset($call['class']) ? $call['class'] . '::' . ($call['function'] ?? '') : ($call['function'] ?? '')
            ),
            array_keys($trace),
            $trace
        ));
    }

    private function sanitizeParameters(array $parameters): array
    {
        $sanitized = [];
        foreach ($parameters as $key => $value) {
            $sanitized[$key] = $this->isSensitiveKey($key) ? '[REDACTED]' : $value;
        }
        return $sanitized;
    }

    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), self::SENSITIVE_HEADERS, true)) {
                $sanitized[$key] = is_array($value) ? ['[REDACTED]'] : '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        return array_reduce(
            self::SENSITIVE_KEYS,
            static fn(bool $carry, string $sensitiveKey): bool => $carry || str_contains(strtolower($key), $sensitiveKey),
            false
        );
    }

    private function sendWebhook(array $payload): void
    {
        $url = rtrim($this->webhookUrl, '/') . '/webhook/error/' . $this->token;
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $this->getHttpClient()->request('POST', $url, [
                    'json' => $payload,
                    'timeout' => $this->timeout,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'ErrorExplorer-SDK/2.0-modern-enhanced',
                        'X-Attempt' => (string)($attempt + 1),
                        'X-Max-Attempts' => (string)($this->maxRetries + 1),
                        'X-SDK-Services' => $this->getEnabledServicesHeader(),
                    ],
                ]);

                return;

            } catch (Throwable $e) {
                $lastException = $e;

                if ($attempt < $this->maxRetries) {
                    usleep(100_000 * ($attempt + 1));
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Webhook failed without exception');
    }
    
    private function getEnabledServicesHeader(): string 
    {
        $services = [];
        if ($this->offlineManager) $services[] = 'offline';
        if ($this->quotaManager) $services[] = 'quota';
        if ($this->rateLimiter) $services[] = 'rate-limit';
        if ($this->retryManager) $services[] = 'retry';
        if ($this->sdkMonitor) $services[] = 'monitor';
        if ($this->securityValidator) $services[] = 'security';
        if ($this->batchManager) $services[] = 'batch';
        if ($this->compressionService) $services[] = 'compression';
        if ($this->circuitBreaker) $services[] = 'circuit-breaker';
        
        return implode(',', $services);
    }
    
    /**
     * Get comprehensive service statistics
     */
    public function getStats(): array
    {
        return [
            'quota' => $this->quotaManager?->getStats(),
            'rate_limiter' => $this->rateLimiter?->getStats(),
            'sdk_monitor' => $this->sdkMonitor?->getHealthReport(),
            'circuit_breaker' => $this->circuitBreaker ? ['state' => $this->circuitBreaker->getState()] : null,
            'offline_queue' => $this->offlineManager?->getQueueStats(),
            'batch_manager' => $this->batchManager?->getStats(),
        ];
    }
    
    /**
     * Flush pending batches
     */
    public function flushBatch(): void
    {
        $this->batchManager?->flush();
    }
    
    /**
     * Flush offline queue
     */
    public function flushOfflineQueue(): void
    {
        $this->offlineManager?->flushQueue();
    }
    
    /**
     * Send data directly (used by batch manager and offline manager)
     */
    public function sendData(array $data): void
    {
        $this->sendWebhook($data);
    }
    
    /**
     * Clean up resources
     */
    public function destroy(): void
    {
        $this->offlineManager?->destroy();
        $this->quotaManager?->destroy();
        $this->rateLimiter?->destroy();
        $this->batchManager?->destroy();
        $this->circuitBreaker?->reset();
    }
}