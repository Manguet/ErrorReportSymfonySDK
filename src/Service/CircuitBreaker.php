<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Circuit breaker pattern implementation for error reporting resilience
 */
readonly class CircuitBreaker
{
    public const STATE_CLOSED = 'CLOSED';
    public const STATE_OPEN = 'OPEN';
    public const STATE_HALF_OPEN = 'HALF_OPEN';

    private const STATE_KEY = 'error_explorer_circuit_breaker_state';
    private const REQUESTS_KEY = 'error_explorer_circuit_breaker_requests';

    public function __construct(
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private array $config = []
    ) {
        $this->config = array_merge([
            'failureThreshold' => 5, // Number of failures to trigger open state
            'resetTimeout' => 60000, // Time in milliseconds before attempting reset
            'monitoringPeriod' => 300000, // 5 minutes in milliseconds  
            'minimumRequests' => 3 // Minimum requests before evaluating failure rate
        ], $config);
    }

    /**
     * Execute an operation with circuit breaker protection
     */
    public function execute(callable $operation): mixed
    {
        $state = $this->getState();

        if ($state['state'] === self::STATE_OPEN) {
            if ($this->shouldAttemptReset($state)) {
                $this->setState(self::STATE_HALF_OPEN);
                $state = $this->getState();
                
                $this->logger->info('Circuit breaker moved from OPEN to HALF_OPEN');
            } else {
                throw new \RuntimeException('Circuit breaker is OPEN - operation not executed');
            }
        }

        try {
            $startTime = microtime(true);
            $result = $operation();
            $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            
            $this->onSuccess();
            
            $this->logger->debug('Circuit breaker operation succeeded', [
                'duration' => $duration,
                'state' => $state['state']
            ]);
            
            return $result;
        } catch (\Exception $error) {
            $this->onFailure();
            
            $this->logger->warning('Circuit breaker operation failed', [
                'error' => $error->getMessage(),
                'state' => $state['state']
            ]);
            
            throw $error;
        }
    }

    /**
     * Handle successful operation
     */
    private function onSuccess(): void
    {
        try {
            $state = $this->getState();
            $now = time() * 1000;
            
            // Record successful request
            $this->recordRequest($now, true);
            
            if ($state['state'] === self::STATE_HALF_OPEN) {
                $this->setState(self::STATE_CLOSED);
                $this->logger->info('Circuit breaker moved from HALF_OPEN to CLOSED after successful operation');
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to handle circuit breaker success', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle failed operation
     */
    private function onFailure(): void
    {
        try {
            $now = time() * 1000;
            
            // Record failed request
            $this->recordRequest($now, false);
            
            if ($this->shouldOpenCircuit()) {
                $this->setState(self::STATE_OPEN);
                $this->logger->warning('Circuit breaker opened due to failure threshold');
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to handle circuit breaker failure', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record a request (success or failure)
     */
    private function recordRequest(int $timestamp, bool $success): void
    {
        try {
            $requests = $this->getRequests();
            $requests[] = [
                'timestamp' => $timestamp,
                'success' => $success
            ];
            
            $this->saveRequests($requests);
        } catch (\Exception $e) {
            $this->logger->error('Failed to record request', [
                'error' => $e->getMessage(),
                'timestamp' => $timestamp,
                'success' => $success
            ]);
        }
    }

    /**
     * Clean up old request records
     */
    #[AsPeriodicTask('1 hour')]
    public function cleanup(): void
    {
        try {
            $cutoff = (time() * 1000) - $this->config['monitoringPeriod'];
            $requests = $this->getRequests();
            
            $originalCount = count($requests);
            $requests = array_values(array_filter($requests, fn($request) => $request['timestamp'] > $cutoff));
            
            $removedCount = $originalCount - count($requests);
            if ($removedCount > 0) {
                $this->logger->debug('Circuit breaker cleanup removed old requests', [
                    'removed' => $removedCount,
                    'remaining' => count($requests)
                ]);
            }
            
            $this->saveRequests($requests);
        } catch (\Exception $e) {
            $this->logger->error('Circuit breaker cleanup failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Determine if circuit should be opened
     */
    private function shouldOpenCircuit(): bool
    {
        try {
            $requests = $this->getRecentRequests();

            if (count($requests) < $this->config['minimumRequests']) {
                return false;
            }

            $failures = array_filter($requests, fn($request) => !$request['success']);
            
            $failureRate = count($failures) / count($requests);
            $failureThreshold = $this->config['failureThreshold'] / 10; // Convert to percentage (0.5 = 50%)

            $shouldOpen = $failureRate >= $failureThreshold;
            
            if ($shouldOpen) {
                $this->logger->info('Circuit breaker should open', [
                    'failureRate' => $failureRate,
                    'failureThreshold' => $failureThreshold,
                    'totalRequests' => count($requests),
                    'failures' => count($failures)
                ]);
            }

            return $shouldOpen;
        } catch (\Exception $e) {
            $this->logger->error('Failed to determine if circuit should open', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Determine if reset should be attempted
     */
    private function shouldAttemptReset(array $state): bool
    {
        $now = time() * 1000;
        $stateChangeTime = $state['stateChangeTime'] ?? $now;
        
        return ($now - $stateChangeTime) >= $this->config['resetTimeout'];
    }

    /**
     * Check if operation can be executed
     */
    public function canExecute(): bool
    {
        try {
            $state = $this->getState();
            
            if ($state['state'] === self::STATE_CLOSED) {
                return true;
            }

            if ($state['state'] === self::STATE_OPEN && $this->shouldAttemptReset($state)) {
                $this->setState(self::STATE_HALF_OPEN);
                return true;
            }

            return $state['state'] === self::STATE_HALF_OPEN;
        } catch (\Exception $e) {
            $this->logger->error('Failed to check if circuit breaker can execute', ['error' => $e->getMessage()]);
            return true; // Default to allowing execution on error
        }
    }

    /**
     * Get circuit breaker statistics
     */
    public function getStats(): array
    {
        try {
            $state = $this->getState();
            $requests = $this->getRecentRequests();
            
            $now = time() * 1000;
            $totalRequests = count($requests);
            $failures = array_filter($requests, fn($request) => !$request['success']);
            $failureRate = $totalRequests > 0 ? count($failures) / $totalRequests : 0;
            
            $stats = [
                'state' => $state['state'],
                'failureCount' => $state['failureCount'] ?? 0,
                'successCount' => $state['successCount'] ?? 0,
                'totalRequests' => $totalRequests,
                'failureRate' => $failureRate,
                'timeInCurrentState' => $now - ($state['stateChangeTime'] ?? $now),
                'lastFailureTime' => $state['lastFailureTime'] ?? null,
                'canExecute' => $this->canExecute(),
                'config' => $this->config
            ];

            if ($state['state'] === self::STATE_OPEN) {
                $stateChangeTime = $state['stateChangeTime'] ?? $now;
                $stats['nextRetryTime'] = $stateChangeTime + $this->config['resetTimeout'];
                $stats['timeUntilReset'] = max(0, $stats['nextRetryTime'] - $now);
            }

            return $stats;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get circuit breaker stats', ['error' => $e->getMessage()]);
            
            return [
                'state' => self::STATE_CLOSED,
                'failureCount' => 0,
                'successCount' => 0,
                'totalRequests' => 0,
                'failureRate' => 0,
                'timeInCurrentState' => 0,
                'lastFailureTime' => null,
                'canExecute' => true,
                'config' => $this->config,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Reset circuit breaker to initial state
     */
    public function reset(): void
    {
        try {
            $this->setState(self::STATE_CLOSED);
            $this->cache->deleteItem(self::REQUESTS_KEY);
            
            $this->logger->info('Circuit breaker reset to initial state');
        } catch (\Exception $e) {
            $this->logger->error('Failed to reset circuit breaker', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Force circuit breaker to open state
     */
    public function forceOpen(): void
    {
        try {
            $this->setState(self::STATE_OPEN);
            $this->logger->warning('Circuit breaker forced to OPEN state');
        } catch (\Exception $e) {
            $this->logger->error('Failed to force circuit breaker open', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Force circuit breaker to closed state
     */
    public function forceClose(): void
    {
        try {
            $this->setState(self::STATE_CLOSED);
            $this->logger->info('Circuit breaker forced to CLOSED state');
        } catch (\Exception $e) {
            $this->logger->error('Failed to force circuit breaker closed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get current state
     */
    public function getCurrentState(): string
    {
        $state = $this->getState();
        return $state['state'];
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $updates): void
    {
        $this->config = array_merge($this->config, $updates);
        
        // Validate configuration values
        $this->config['failureThreshold'] = max(1, (int) $this->config['failureThreshold']);
        $this->config['resetTimeout'] = max(1000, (int) $this->config['resetTimeout']);
        $this->config['monitoringPeriod'] = max(60000, (int) $this->config['monitoringPeriod']);
        $this->config['minimumRequests'] = max(1, (int) $this->config['minimumRequests']);
        
        $this->logger->info('Circuit breaker configuration updated', ['config' => $this->config]);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Create a circuit breaker protected version of a function
     */
    public function protect(callable $function): \Closure
    {
        return function (...$args) use ($function) {
            return $this->execute(function () use ($function, $args) {
                return $function(...$args);
            });
        };
    }

    /**
     * Test circuit breaker functionality
     */
    public function test(): array
    {
        try {
            $results = [];
            $originalState = $this->getCurrentState();
            
            // Test successful operation
            $result = $this->execute(fn() => 'success');
            $results['success_test'] = $result === 'success';
            
            // Test failed operation
            try {
                $this->execute(function () {
                    throw new \RuntimeException('Test failure');
                });
                $results['failure_test'] = false; // Should have thrown
            } catch (\RuntimeException $e) {
                $results['failure_test'] = $e->getMessage() === 'Test failure';
            }
            
            // Test state management
            $this->forceOpen();
            $results['force_open'] = $this->getCurrentState() === self::STATE_OPEN;
            
            $this->forceClose();
            $results['force_close'] = $this->getCurrentState() === self::STATE_CLOSED;
            
            $results['overall'] = array_reduce($results, fn($carry, $item) => $carry && $item, true);
            
            // Restore original state
            $this->setState($originalState);
            
            $this->logger->info('Circuit breaker test completed', ['results' => $results]);
            
            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Circuit breaker test failed', ['error' => $e->getMessage()]);
            
            return [
                'success_test' => false,
                'failure_test' => false,
                'force_open' => false,
                'force_close' => false,
                'overall' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get state from cache
     */
    private function getState(): array
    {
        try {
            $cacheItem = $this->cache->getItem(self::STATE_KEY);
            
            if (!$cacheItem->isHit()) {
                return $this->getDefaultState();
            }
            
            $state = $cacheItem->get();
            return array_merge($this->getDefaultState(), is_array($state) ? $state : []);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get circuit breaker state from cache', ['error' => $e->getMessage()]);
            return $this->getDefaultState();
        }
    }

    /**
     * Get default state structure
     */
    private function getDefaultState(): array
    {
        return [
            'state' => self::STATE_CLOSED,
            'failureCount' => 0,
            'successCount' => 0,
            'lastFailureTime' => null,
            'stateChangeTime' => time() * 1000
        ];
    }

    /**
     * Set state in cache
     */
    private function setState(string $newState): void
    {
        try {
            $currentState = $this->getState();
            $now = time() * 1000;
            
            $state = [
                'state' => $newState,
                'failureCount' => $currentState['failureCount'] ?? 0,
                'successCount' => $currentState['successCount'] ?? 0,
                'lastFailureTime' => $currentState['lastFailureTime'] ?? null,
                'stateChangeTime' => $now
            ];
            
            if ($newState === self::STATE_CLOSED) {
                $state['failureCount'] = 0;
            }
            
            $cacheItem = $this->cache->getItem(self::STATE_KEY);
            $cacheItem->set($state);
            // Store for longer than monitoring period
            $cacheItem->expiresAfter((int) (($this->config['monitoringPeriod'] * 2) / 1000));
            
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to set circuit breaker state', [
                'error' => $e->getMessage(),
                'newState' => $newState
            ]);
        }
    }

    /**
     * Get requests from cache
     */
    private function getRequests(): array
    {
        try {
            $cacheItem = $this->cache->getItem(self::REQUESTS_KEY);
            
            if (!$cacheItem->isHit()) {
                return [];
            }
            
            $requests = $cacheItem->get();
            return is_array($requests) ? $requests : [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get requests from cache', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get recent requests within monitoring period
     */
    private function getRecentRequests(): array
    {
        $requests = $this->getRequests();
        $cutoff = (time() * 1000) - $this->config['monitoringPeriod'];
        
        return array_filter($requests, fn($request) => $request['timestamp'] > $cutoff);
    }

    /**
     * Save requests to cache
     */
    private function saveRequests(array $requests): void
    {
        try {
            // Clean up old requests before saving
            $cutoff = (time() * 1000) - $this->config['monitoringPeriod'];
            $requests = array_values(array_filter($requests, fn($request) => $request['timestamp'] > $cutoff));
            
            $cacheItem = $this->cache->getItem(self::REQUESTS_KEY);
            $cacheItem->set($requests);
            // Store for monitoring period duration
            $cacheItem->expiresAfter((int) ($this->config['monitoringPeriod'] / 1000));
            
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save requests to cache', [
                'error' => $e->getMessage(),
                'requestCount' => count($requests)
            ]);
        }
    }
}