<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Monitors SDK performance and health
 */
readonly class SDKMonitor
{
    private const METRICS_KEY = 'error_explorer_sdk_metrics';
    private const PERFORMANCE_KEY = 'error_explorer_performance_entries';
    private const MAX_PERFORMANCE_ENTRIES = 100;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private int $startTime
    ) {
        $this->startTime = time();
    }

    /**
     * Track an error being reported
     */
    public function trackError(\Exception $error, array $context = []): void
    {
        try {
            $metrics = $this->getMetrics();
            $metrics['errorsReported']++;
            $metrics['lastErrorTime'] = time();
            
            if (!empty($context['suppressed'])) {
                $metrics['errorsSuppressed']++;
            }
            
            $this->saveMetrics($metrics);
            
            $this->logger->info('Error tracked in SDK monitor', [
                'errorClass' => get_class($error),
                'message' => $error->getMessage(),
                'suppressed' => !empty($context['suppressed'])
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to track error in SDK monitor', [
                'error' => $e->getMessage(),
                'originalError' => $error->getMessage()
            ]);
        }
    }

    /**
     * Track a suppressed error
     */
    public function trackSuppressedError(string $reason): void
    {
        try {
            $metrics = $this->getMetrics();
            $metrics['errorsSuppressed']++;
            $this->saveMetrics($metrics);
            
            $this->logger->info('Suppressed error tracked', ['reason' => $reason]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to track suppressed error', [
                'error' => $e->getMessage(),
                'reason' => $reason
            ]);
        }
    }

    /**
     * Track a retry attempt
     */
    public function trackRetryAttempt(): void
    {
        try {
            $metrics = $this->getMetrics();
            $metrics['retryAttempts']++;
            $this->saveMetrics($metrics);
            
            $this->logger->debug('Retry attempt tracked');
        } catch (\Exception $e) {
            $this->logger->error('Failed to track retry attempt', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Track performance of an operation
     */
    public function trackPerformance(string $operation, float $duration, bool $success = true): void
    {
        try {
            $entry = [
                'operation' => $operation,
                'duration' => $duration,
                'timestamp' => time(),
                'success' => $success
            ];

            $performanceEntries = $this->getPerformanceEntries();
            $performanceEntries[] = $entry;
            
            // Keep only recent entries
            if (count($performanceEntries) > self::MAX_PERFORMANCE_ENTRIES) {
                $performanceEntries = array_slice($performanceEntries, -self::MAX_PERFORMANCE_ENTRIES);
            }
            
            $this->savePerformanceEntries($performanceEntries);
            $this->updateAverageResponseTime($performanceEntries);
            
            $this->logger->debug('Performance tracked', [
                'operation' => $operation,
                'duration' => $duration,
                'success' => $success
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to track performance', [
                'error' => $e->getMessage(),
                'operation' => $operation
            ]);
        }
    }

    /**
     * Update offline queue size
     */
    public function updateOfflineQueueSize(int $size): void
    {
        try {
            $metrics = $this->getMetrics();
            $metrics['offlineQueueSize'] = $size;
            $this->saveMetrics($metrics);
            
            $this->logger->debug('Offline queue size updated', ['size' => $size]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update offline queue size', [
                'error' => $e->getMessage(),
                'size' => $size
            ]);
        }
    }

    /**
     * Update average response time based on recent entries
     */
    private function updateAverageResponseTime(array $performanceEntries): void
    {
        try {
            if (empty($performanceEntries)) {
                $metrics = $this->getMetrics();
                $metrics['averageResponseTime'] = 0;
                $this->saveMetrics($metrics);
                return;
            }

            // Use only the last 20 entries for average
            $recentEntries = array_slice($performanceEntries, -20);
            $total = array_sum(array_column($recentEntries, 'duration'));
            $average = $total / count($recentEntries);
            
            $metrics = $this->getMetrics();
            $metrics['averageResponseTime'] = $average;
            $this->saveMetrics($metrics);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update average response time', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Perform health check
     */
    #[AsPeriodicTask('30 seconds')]
    public function performHealthCheck(): void
    {
        try {
            $this->updateMemoryUsage();
            $this->cleanupOldPerformanceEntries();
            
            $this->logger->debug('Health check performed');
        } catch (\Exception $e) {
            $this->logger->error('Health check failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Update memory usage metric
     */
    private function updateMemoryUsage(): void
    {
        try {
            $metrics = $this->getMetrics();
            $metrics['memoryUsage'] = memory_get_usage(true);
            $this->saveMetrics($metrics);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update memory usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clean up old performance entries
     */
    #[AsPeriodicTask('1 hour')]
    public function cleanupOldPerformanceEntries(): void
    {
        try {
            $cutoff = time() - (60 * 60); // Keep entries from last hour
            $performanceEntries = $this->getPerformanceEntries();
            
            $originalCount = count($performanceEntries);
            $performanceEntries = array_values(array_filter(
                $performanceEntries, 
                fn($entry) => $entry['timestamp'] > $cutoff
            ));
            
            $removedCount = $originalCount - count($performanceEntries);
            if ($removedCount > 0) {
                $this->logger->info('Cleaned up old performance entries', [
                    'removed' => $removedCount,
                    'remaining' => count($performanceEntries)
                ]);
            }
            
            $this->savePerformanceEntries($performanceEntries);
        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup old performance entries', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get current metrics
     */
    public function getMetrics(): array
    {
        try {
            $cacheItem = $this->cache->getItem(self::METRICS_KEY);
            
            if (!$cacheItem->isHit()) {
                return $this->getDefaultMetrics();
            }
            
            $metrics = $cacheItem->get();
            $defaultMetrics = $this->getDefaultMetrics();
            
            return array_merge($defaultMetrics, is_array($metrics) ? $metrics : []);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get metrics from cache', ['error' => $e->getMessage()]);
            return $this->getDefaultMetrics();
        }
    }

    /**
     * Get default metrics structure
     */
    private function getDefaultMetrics(): array
    {
        return [
            'errorsReported' => 0,
            'errorsSuppressed' => 0,
            'retryAttempts' => 0,
            'offlineQueueSize' => 0,
            'averageResponseTime' => 0,
            'uptime' => time() - $this->startTime,
            'memoryUsage' => memory_get_usage(true),
            'lastErrorTime' => null
        ];
    }

    /**
     * Assess SDK health
     */
    public function assessHealth(): array
    {
        try {
            $metrics = $this->getMetrics();
            $issues = [];
            $recommendations = [];
            $score = 100;

            // Check error suppression rate
            $errorRate = $metrics['errorsReported'] > 0 ? 
                ($metrics['errorsSuppressed'] / $metrics['errorsReported']) * 100 : 0;
            
            if ($errorRate > 50) {
                $issues[] = 'High error suppression rate';
                $recommendations[] = 'Review error filtering configuration';
                $score -= 20;
            }

            // Check average response time
            if ($metrics['averageResponseTime'] > 5000) {
                $issues[] = 'Slow average response time';
                $recommendations[] = 'Check network connectivity and server performance';
                $score -= 15;
            }

            // Check offline queue size
            if ($metrics['offlineQueueSize'] > 10) {
                $issues[] = 'Large offline queue';
                $recommendations[] = 'Check network connectivity';
                $score -= 10;
            }

            // Check memory usage (> 50MB)
            if ($metrics['memoryUsage'] > 50 * 1024 * 1024) {
                $issues[] = 'High memory usage';
                $recommendations[] = 'Consider reducing breadcrumb retention or queue sizes';
                $score -= 10;
            }

            // Check if errors are being reported recently
            $timeSinceLastError = $metrics['lastErrorTime'] ? (time() - $metrics['lastErrorTime']) : null;
            if ($timeSinceLastError !== null && $timeSinceLastError > 24 * 60 * 60) {
                $issues[] = 'No errors reported in 24 hours';
                $recommendations[] = 'Verify error reporting is working correctly';
                $score -= 5;
            }

            // Determine health status
            $status = match (true) {
                $score >= 80 => 'healthy',
                $score >= 60 => 'degraded',
                default => 'unhealthy'
            };

            return [
                'status' => $status,
                'score' => max(0, $score),
                'issues' => $issues,
                'recommendations' => $recommendations,
                'metrics' => $metrics
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to assess health', ['error' => $e->getMessage()]);
            return [
                'status' => 'unknown',
                'score' => 0,
                'issues' => ['Health assessment failed'],
                'recommendations' => ['Check system logs'],
                'metrics' => $this->getDefaultMetrics()
            ];
        }
    }

    /**
     * Get performance statistics
     */
    public function getPerformanceStats(): array
    {
        try {
            $entries = $this->getPerformanceEntries();
            
            if (empty($entries)) {
                return [
                    'totalOperations' => 0,
                    'successRate' => 0,
                    'averageDuration' => 0,
                    'operations' => []
                ];
            }

            $totalOperations = count($entries);
            $successfulOperations = count(array_filter($entries, fn($entry) => $entry['success']));
            
            $successRate = ($successfulOperations / $totalOperations) * 100;
            $averageDuration = array_sum(array_column($entries, 'duration')) / $totalOperations;
            
            // Group by operation type
            $operationStats = [];
            foreach ($entries as $entry) {
                $op = $entry['operation'];
                if (!isset($operationStats[$op])) {
                    $operationStats[$op] = [
                        'count' => 0,
                        'successes' => 0,
                        'totalDuration' => 0
                    ];
                }
                
                $operationStats[$op]['count']++;
                $operationStats[$op]['totalDuration'] += $entry['duration'];
                
                if ($entry['success']) {
                    $operationStats[$op]['successes']++;
                }
            }

            // Calculate stats for each operation
            foreach ($operationStats as $op => &$stats) {
                $stats['successRate'] = ($stats['successes'] / $stats['count']) * 100;
                $stats['averageDuration'] = $stats['totalDuration'] / $stats['count'];
            }

            return [
                'totalOperations' => $totalOperations,
                'successRate' => $successRate,
                'averageDuration' => $averageDuration,
                'operations' => $operationStats
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get performance stats', ['error' => $e->getMessage()]);
            return [
                'totalOperations' => 0,
                'successRate' => 0,
                'averageDuration' => 0,
                'operations' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Reset all metrics
     */
    public function reset(): void
    {
        try {
            $this->cache->deleteItem(self::METRICS_KEY);
            $this->cache->deleteItem(self::PERFORMANCE_KEY);
            
            $this->logger->info('SDK monitor reset');
        } catch (\Exception $e) {
            $this->logger->error('Failed to reset SDK monitor', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Save metrics to cache
     */
    private function saveMetrics(array $metrics): void
    {
        try {
            $cacheItem = $this->cache->getItem(self::METRICS_KEY);
            $cacheItem->set($metrics);
            $cacheItem->expiresAfter(86400); // 24 hours
            
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save metrics to cache', [
                'error' => $e->getMessage(),
                'metrics' => $metrics
            ]);
        }
    }

    /**
     * Get performance entries from cache
     */
    private function getPerformanceEntries(): array
    {
        try {
            $cacheItem = $this->cache->getItem(self::PERFORMANCE_KEY);
            
            if (!$cacheItem->isHit()) {
                return [];
            }
            
            $entries = $cacheItem->get();
            return is_array($entries) ? $entries : [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get performance entries from cache', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Save performance entries to cache
     */
    private function savePerformanceEntries(array $entries): void
    {
        try {
            $cacheItem = $this->cache->getItem(self::PERFORMANCE_KEY);
            $cacheItem->set($entries);
            $cacheItem->expiresAfter(3600); // 1 hour
            
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save performance entries to cache', [
                'error' => $e->getMessage(),
                'entryCount' => count($entries)
            ]);
        }
    }
}