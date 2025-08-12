<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Manages retry logic with exponential backoff for failed operations
 */
readonly class RetryManager
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private array $config = []
    ) {
        $this->config = array_merge([
            'maxRetries' => 3,
            'initialDelay' => 1000, // milliseconds
            'maxDelay' => 30000, // 30 seconds
            'backoffMultiplier' => 2,
            'jitter' => true
        ], $config);
    }

    /**
     * Execute an operation with retry logic
     */
    public function executeWithRetry(callable $operation, array $customConfig = []): array
    {
        $config = array_merge($this->config, $customConfig);
        $startTime = microtime(true);
        $lastError = null;
        $attempts = 0;

        for ($attempt = 0; $attempt <= $config['maxRetries']; $attempt++) {
            $attempts++;
            
            try {
                $result = $operation();
                
                $totalTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
                
                $this->logger->info('Operation succeeded after retries', [
                    'attempts' => $attempts,
                    'totalTime' => $totalTime
                ]);
                
                return [
                    'success' => true,
                    'result' => $result,
                    'attempts' => $attempts,
                    'totalTime' => $totalTime
                ];
            } catch (\Exception $error) {
                $lastError = $error;

                $this->logger->warning('Operation failed, attempt {attempt}', [
                    'attempt' => $attempt + 1,
                    'maxRetries' => $config['maxRetries'] + 1,
                    'error' => $error->getMessage(),
                    'errorClass' => get_class($error)
                ]);

                // Don't retry on the last attempt
                if ($attempt === $config['maxRetries']) {
                    break;
                }

                // Don't retry on certain types of errors
                if ($this->shouldNotRetry($error)) {
                    $this->logger->info('Stopping retries due to non-retryable error', [
                        'error' => $error->getMessage(),
                        'errorClass' => get_class($error)
                    ]);
                    break;
                }

                // Calculate delay for next attempt
                $delay = $this->calculateDelay($attempt, $config);
                
                $this->logger->debug('Waiting before next retry', [
                    'delay' => $delay,
                    'nextAttempt' => $attempt + 2
                ]);
                
                // Sleep for the calculated delay (convert milliseconds to microseconds)
                usleep($delay * 1000);
            }
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        
        $this->logger->error('Operation failed after all retry attempts', [
            'attempts' => $attempts,
            'totalTime' => $totalTime,
            'finalError' => $lastError?->getMessage(),
            'errorClass' => $lastError ? get_class($lastError) : null
        ]);

        return [
            'success' => false,
            'error' => $lastError?->getMessage() ?? 'Unknown error',
            'errorCode' => $lastError?->getCode() ?? 0,
            'errorClass' => $lastError ? get_class($lastError) : null,
            'attempts' => $attempts,
            'totalTime' => $totalTime
        ];
    }

    /**
     * Determine if we should not retry based on the error
     */
    private function shouldNotRetry(\Exception $error): bool
    {
        // Don't retry on HTTP client errors (4xx)
        if ($error instanceof ClientException) {
            return true;
        }

        // Don't retry on validation errors
        if ($error instanceof \InvalidArgumentException || 
            $error instanceof \TypeError) {
            return true;
        }

        $message = $error->getMessage();

        // Don't retry on HTTP client errors (4xx) based on message
        if (preg_match('/40[0-9]/', $message)) {
            return true;
        }

        // Don't retry on validation errors based on message
        if (stripos($message, 'validation') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Calculate the delay for the next retry attempt
     */
    private function calculateDelay(int $attempt, array $config): int
    {
        // Calculate exponential backoff
        $delay = $config['initialDelay'] * pow($config['backoffMultiplier'], $attempt);
        
        // Apply max delay cap
        $delay = min($delay, $config['maxDelay']);
        
        // Add jitter to prevent thundering herd
        if ($config['jitter']) {
            $jitterAmount = $delay * 0.1; // 10% jitter
            $jitter = (random_int(0, 1000) / 1000 - 0.5) * 2 * $jitterAmount;
            $delay += $jitter;
        }
        
        return max(0, (int) round($delay));
    }

    /**
     * Simple retry helper method
     */
    public function retry(callable $operation): mixed
    {
        $result = $this->executeWithRetry($operation);
        
        if ($result['success']) {
            return $result['result'];
        }
        
        throw new \Exception($result['error'], $result['errorCode'] ?? 0);
    }

    /**
     * Create a retryable version of a function
     */
    public function makeRetryable(callable $function, array $customConfig = []): \Closure
    {
        return function (...$args) use ($function, $customConfig) {
            return $this->retry(function() use ($function, $args) {
                return $function(...$args);
            });
        };
    }

    /**
     * Retry with Symfony HTTP Client integration
     */
    public function retryHttpRequest(string $method, string $url, array $options = []): array
    {
        return $this->executeWithRetry(function() use ($method, $url, $options) {
            $response = $this->httpClient->request($method, $url, $options);
            
            $statusCode = $response->getStatusCode();
            
            // Throw exception for HTTP errors that should trigger retries
            if ($statusCode >= 500 || $statusCode === 429) {
                throw new ServerException($response);
            }
            
            // For 4xx errors, don't retry (will be caught by shouldNotRetry)
            if ($statusCode >= 400) {
                throw new ClientException($response);
            }
            
            return $response;
        });
    }

    /**
     * Retry HTTP request with response body
     */
    public function retryHttpRequestWithBody(string $method, string $url, array $options = []): array
    {
        $result = $this->retryHttpRequest($method, $url, $options);
        
        if ($result['success']) {
            /** @var ResponseInterface $response */
            $response = $result['result'];
            $result['body'] = $response->getContent();
            $result['headers'] = $response->getHeaders();
            $result['statusCode'] = $response->getStatusCode();
        }
        
        return $result;
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $updates): void
    {
        $this->config = array_merge($this->config, $updates);
        
        // Validate configuration
        $this->config['maxRetries'] = max(0, (int) $this->config['maxRetries']);
        $this->config['initialDelay'] = max(100, (int) $this->config['initialDelay']);
        $this->config['maxDelay'] = max(1000, (int) $this->config['maxDelay']);
        $this->config['backoffMultiplier'] = max(1.1, (float) $this->config['backoffMultiplier']);
        
        $this->logger->info('Retry manager configuration updated', ['config' => $this->config]);
    }

    /**
     * Get retry statistics for a callable
     */
    public function benchmarkRetries(callable $operation, int $iterations = 10): array
    {
        $results = [];
        $totalSuccess = 0;
        $totalAttempts = 0;
        $totalTime = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $result = $this->executeWithRetry($operation);
            $results[] = $result;
            
            if ($result['success']) {
                $totalSuccess++;
            }
            
            $totalAttempts += $result['attempts'];
            $totalTime += $result['totalTime'];
        }

        return [
            'iterations' => $iterations,
            'successRate' => $totalSuccess / $iterations,
            'averageAttempts' => $totalAttempts / $iterations,
            'averageTime' => $totalTime / $iterations,
            'results' => $results
        ];
    }

    /**
     * Create a circuit breaker pattern with retries
     */
    public function withCircuitBreaker(callable $operation, int $failureThreshold = 5, int $resetTimeoutMs = 60000): \Closure
    {
        static $failures = 0;
        static $lastFailureTime = 0;
        static $state = 'CLOSED'; // CLOSED, OPEN, HALF_OPEN
        
        return function (...$args) use ($operation, $failureThreshold, $resetTimeoutMs, &$failures, &$lastFailureTime, &$state) {
            $now = time() * 1000;
            
            // Check if circuit should be reset
            if ($state === 'OPEN' && ($now - $lastFailureTime) > $resetTimeoutMs) {
                $state = 'HALF_OPEN';
                $failures = 0;
            }
            
            // If circuit is open, don't execute
            if ($state === 'OPEN') {
                throw new \Exception('Circuit breaker is OPEN - operation blocked');
            }
            
            try {
                $result = $this->executeWithRetry(function() use ($operation, $args) {
                    return $operation(...$args);
                });
                
                if ($result['success']) {
                    // Success - close circuit
                    if ($state === 'HALF_OPEN') {
                        $state = 'CLOSED';
                        $failures = 0;
                    }
                    return $result['result'];
                } else {
                    throw new \Exception($result['error'], $result['errorCode'] ?? 0);
                }
            } catch (\Exception $e) {
                $failures++;
                $lastFailureTime = $now;
                
                // Open circuit if failure threshold reached
                if ($failures >= $failureThreshold) {
                    $state = 'OPEN';
                }
                
                throw $e;
            }
        };
    }
}