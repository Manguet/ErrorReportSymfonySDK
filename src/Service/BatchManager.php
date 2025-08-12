<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Manages batching of errors for efficient sending
 */
class BatchManager
{
    private const BATCH_KEY = 'error_explorer_current_batch';
    private const COUNTER_KEY = 'error_explorer_batch_counter';
    private const HISTORY_KEY = 'error_explorer_batch_history';

    private array $config;
    private bool $processingBatch = false;
    /** @var callable|null */
    private $sendFunction = null;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        array $config = []
    ) {
        $this->config = array_merge([
            'enabled' => true,
            'maxSize' => 10,
            'maxWaitTime' => 5, // seconds
            'maxPayloadSize' => 500 * 1024 // 500KB
        ], $config);
    }

    /**
     * Add an error to the batch
     */
    public function addError(array $errorData, ?callable $sendFunction = null): void
    {
        try {
            if (!$this->config['enabled'] || $sendFunction === null) {
                // If batching disabled, send immediately
                if ($sendFunction !== null) {
                    $batch = [
                        'errors' => [$errorData],
                        'batchId' => $this->generateBatchId(),
                        'timestamp' => date('Y-m-d H:i:s'),
                        'count' => 1
                    ];
                    $sendFunction($batch);
                }
                return;
            }

            $currentBatch = $this->getCurrentBatch();
            $currentBatch[] = $errorData;
            $this->saveCurrentBatch($currentBatch);

            $this->logger->debug('Error added to batch', [
                'batchSize' => count($currentBatch),
                'maxSize' => $this->config['maxSize']
            ]);

            // Check if we should send the batch immediately
            if ($this->shouldSendBatch($currentBatch)) {
                $this->sendCurrentBatch($sendFunction);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to add error to batch', [
                'error' => $e->getMessage(),
                'errorData' => $errorData
            ]);
        }
    }

    /**
     * Check if the batch should be sent immediately
     */
    private function shouldSendBatch(array $currentBatch): bool
    {
        // Check batch size
        if (count($currentBatch) >= $this->config['maxSize']) {
            return true;
        }

        // Check payload size
        $estimatedSize = $this->estimateBatchSize($currentBatch);
        return $estimatedSize >= $this->config['maxPayloadSize'];
    }

    /**
     * Estimate the size of the current batch
     */
    private function estimateBatchSize(array $currentBatch): int
    {
        if (empty($currentBatch)) {
            return 0;
        }

        $batchData = [
            'errors' => $currentBatch,
            'batchId' => 'estimate',
            'timestamp' => date('Y-m-d H:i:s'),
            'count' => count($currentBatch)
        ];

        return strlen(json_encode($batchData, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Send the current batch
     */
    public function sendCurrentBatch(?callable $sendFunction = null): void
    {
        if ($this->processingBatch || $sendFunction === null) {
            return;
        }

        try {
            $this->processingBatch = true;
            $currentBatch = $this->getCurrentBatch();
            
            if (empty($currentBatch)) {
                return;
            }

            $batch = [
                'errors' => $currentBatch,
                'batchId' => $this->generateBatchId(),
                'timestamp' => date('Y-m-d H:i:s'),
                'count' => count($currentBatch)
            ];

            // Clear current batch before sending
            $this->clearCurrentBatch();

            $this->logger->info('Sending batch', [
                'batchId' => $batch['batchId'],
                'count' => $batch['count']
            ]);

            $sendFunction($batch);
            $this->recordBatchHistory($batch);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to send batch', [
                'error' => $e->getMessage(),
                'batchSize' => count($currentBatch ?? [])
            ]);
        } finally {
            $this->processingBatch = false;
        }
    }

    /**
     * Process batch via scheduled task
     */
    #[AsPeriodicTask('5 seconds')]
    public function processScheduledBatch(?callable $sendFunction = null): void
    {
        if (!$this->config['enabled'] || $sendFunction === null) {
            return;
        }

        $currentBatch = $this->getCurrentBatch();
        if (!empty($currentBatch)) {
            $this->sendCurrentBatch($sendFunction);
        }
    }

    /**
     * Flush any pending batch immediately
     */
    public function flush(?callable $sendFunction = null): void
    {
        $currentBatch = $this->getCurrentBatch();
        if (!empty($currentBatch) && $sendFunction !== null) {
            $this->sendCurrentBatch($sendFunction);
        }
    }

    /**
     * Generate a unique batch ID
     */
    private function generateBatchId(): string
    {
        try {
            $counter = $this->getBatchCounter();
            $counter++;
            $this->saveBatchCounter($counter);
            
            $timestamp = time();
            $random = bin2hex(random_bytes(4));
            
            return sprintf('batch_%d_%d_%s', $timestamp, $counter, $random);
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate batch ID', ['error' => $e->getMessage()]);
            return sprintf('batch_%d_%s', time(), uniqid());
        }
    }

    /**
     * Get current batch statistics
     */
    public function getStats(): array
    {
        try {
            $currentBatch = $this->getCurrentBatch();
            
            return [
                'currentBatchSize' => count($currentBatch),
                'hasPendingBatch' => !empty($currentBatch),
                'estimatedPayloadSize' => $this->estimateBatchSize($currentBatch),
                'batchCounter' => $this->getBatchCounter(),
                'config' => $this->config,
                'isProcessing' => $this->processingBatch
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get batch stats', ['error' => $e->getMessage()]);
            return [
                'currentBatchSize' => 0,
                'hasPendingBatch' => false,
                'estimatedPayloadSize' => 0,
                'batchCounter' => 0,
                'config' => $this->config,
                'isProcessing' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $updates): void
    {
        $oldEnabled = $this->config['enabled'];
        $this->config = array_merge($this->config, $updates);

        // If batching was disabled, flush current batch
        if ($oldEnabled && !$this->config['enabled']) {
            $this->flush();
        }

        $this->logger->info('Batch manager configuration updated', ['config' => $this->config]);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Clear the current batch without sending
     */
    public function clearBatch(): void
    {
        try {
            $this->clearCurrentBatch();
            $this->logger->info('Batch cleared');
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear batch', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Split a large batch into smaller chunks
     */
    public function splitBatch(array $errors, ?int $maxSize = null): array
    {
        $maxSize = $maxSize ?? $this->config['maxSize'];
        return array_chunk($errors, $maxSize);
    }

    /**
     * Get batch history (for debugging)
     */
    public function getBatchHistory(): array
    {
        try {
            $cacheItem = $this->cache->getItem(self::HISTORY_KEY);
            
            if (!$cacheItem->isHit()) {
                return [];
            }
            
            $history = $cacheItem->get();
            return is_array($history) ? $history : [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get batch history', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Record batch in history (for debugging)
     */
    public function recordBatchHistory(array $batch): void
    {
        try {
            $history = $this->getBatchHistory();
            $history[] = [
                'batchId' => $batch['batchId'],
                'count' => $batch['count'],
                'timestamp' => $batch['timestamp'],
                'sent_at' => date('Y-m-d H:i:s')
            ];
            
            // Keep only the last 10 entries
            if (count($history) > 10) {
                $history = array_slice($history, -10);
            }
            
            $cacheItem = $this->cache->getItem(self::HISTORY_KEY);
            $cacheItem->set($history);
            $cacheItem->expiresAfter(86400); // 24 hours
            
            $this->cache->save($cacheItem);
            
            $this->logger->debug('Batch history recorded', [
                'batchId' => $batch['batchId'],
                'count' => $batch['count']
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to record batch history', [
                'error' => $e->getMessage(),
                'batchId' => $batch['batchId'] ?? 'unknown'
            ]);
        }
    }

    /**
     * Get current batch from cache
     */
    private function getCurrentBatch(): array
    {
        try {
            $cacheItem = $this->cache->getItem(self::BATCH_KEY);
            
            if (!$cacheItem->isHit()) {
                return [];
            }
            
            $batch = $cacheItem->get();
            return is_array($batch) ? $batch : [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get current batch from cache', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Save current batch to cache
     */
    private function saveCurrentBatch(array $batch): void
    {
        try {
            if (empty($batch)) {
                $this->clearCurrentBatch();
                return;
            }

            $cacheItem = $this->cache->getItem(self::BATCH_KEY);
            $cacheItem->set($batch);
            $cacheItem->expiresAfter($this->config['maxWaitTime'] + 60);
            
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save current batch to cache', [
                'error' => $e->getMessage(),
                'batchSize' => count($batch)
            ]);
        }
    }

    /**
     * Clear current batch from cache
     */
    private function clearCurrentBatch(): void
    {
        try {
            $this->cache->deleteItem(self::BATCH_KEY);
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear current batch from cache', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get batch counter from cache
     */
    private function getBatchCounter(): int
    {
        try {
            $cacheItem = $this->cache->getItem(self::COUNTER_KEY);
            
            if (!$cacheItem->isHit()) {
                return 0;
            }
            
            return (int) $cacheItem->get();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get batch counter from cache', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Save batch counter to cache
     */
    private function saveBatchCounter(int $counter): void
    {
        try {
            $cacheItem = $this->cache->getItem(self::COUNTER_KEY);
            $cacheItem->set($counter);
            $cacheItem->expiresAfter(86400 * 30); // 30 days
            
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save batch counter to cache', [
                'error' => $e->getMessage(),
                'counter' => $counter
            ]);
        }
    }

    /**
     * Add an error to the current batch (alias for addError)
     */
    public function addToBatch(array $payload): void
    {
        $this->addError($payload, $this->sendFunction);
    }

    /**
     * Set the send function callback
     */
    public function setSendFunction(callable $sendFunction): void
    {
        $this->sendFunction = $sendFunction;
    }

    /**
     * Clean up resources
     */
    public function destroy(): void
    {
        // Flush any remaining batch
        $this->flush($this->sendFunction);
        $this->logger->debug('BatchManager resources cleaned up');
    }

    /**
     * Clean up old data
     */
    #[AsPeriodicTask('1 hour')]
    public function cleanup(): void
    {
        try {
            // Cleanup is handled by cache expiration, but we can do additional cleanup here if needed
            $this->logger->debug('Batch manager cleanup completed');
        } catch (\Exception $e) {
            $this->logger->error('Batch manager cleanup failed', ['error' => $e->getMessage()]);
        }
    }
}