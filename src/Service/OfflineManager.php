<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Manages offline error storage using Symfony Cache
 */
class OfflineManager
{
    private const QUEUE_KEY = 'error_explorer_offline_queue';
    private const MAX_RETRY_ATTEMPTS = 3;

    /** @var callable|null */
    private $sendFunction = null;
    private bool $processingQueue = false;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly int $maxQueueSize = 50,
        private readonly int $maxAge = 86400 // 24 hours
    ) {}

    /**
     * Handle an error, either sending it immediately or queuing it
     */
    public function handleError(array $errorData, ?callable $sendFunction = null): bool
    {
        // Try to send immediately if online
        if ($this->isOnline() && $sendFunction !== null) {
            try {
                $result = $sendFunction($errorData);
                if ($result === true) {
                    return true;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to send error immediately, queuing for offline processing', [
                    'error' => $e->getMessage(),
                    'errorData' => $errorData
                ]);
            }
        }
        
        // Queue the error for later processing
        return $this->queueError($errorData);
    }

    /**
     * Queue an error for offline processing
     */
    private function queueError(array $errorData): bool
    {
        try {
            $queue = $this->getQueue();
            
            $queueItem = [
                'id' => $this->generateId(),
                'errorData' => $errorData,
                'timestamp' => time(),
                'attempts' => 0
            ];
            
            $queue[] = $queueItem;
            
            // Enforce max queue size
            if (count($queue) > $this->maxQueueSize) {
                // Sort by timestamp and keep only the most recent
                usort($queue, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
                $queue = array_slice($queue, 0, $this->maxQueueSize);
            }
            
            $this->saveQueue($queue);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to queue error for offline processing', [
                'error' => $e->getMessage(),
                'errorData' => $errorData
            ]);
            return false;
        }
    }

    /**
     * Process the offline queue
     */
    #[AsPeriodicTask('1 minute', jitter: 10)]
    public function processQueue(?callable $sendFunction = null): void
    {
        $sendFn = $sendFunction ?? $this->sendFunction;
        if ($this->processingQueue || !$this->isOnline() || $sendFn === null) {
            return;
        }
        
        $this->processingQueue = true;
        
        try {
            $queue = $this->getQueue();
            $processedItems = [];
            
            foreach ($queue as $key => $item) {
                try {
                    $result = $sendFn($item['errorData']);
                    if ($result === true) {
                        $processedItems[] = $item['id'];
                        $this->logger->info('Successfully processed queued error', [
                            'id' => $item['id']
                        ]);
                    } else {
                        // Increment attempts
                        $queue[$key]['attempts']++;
                        
                        // Remove if too many attempts
                        if ($queue[$key]['attempts'] >= self::MAX_RETRY_ATTEMPTS) {
                            $processedItems[] = $item['id'];
                            $this->logger->warning('Discarding queued error after max attempts', [
                                'id' => $item['id'],
                                'attempts' => $queue[$key]['attempts']
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    // Increment attempts on exception
                    $queue[$key]['attempts']++;
                    
                    if ($queue[$key]['attempts'] >= self::MAX_RETRY_ATTEMPTS) {
                        $processedItems[] = $item['id'];
                        $this->logger->error('Discarding queued error after max attempts due to exception', [
                            'id' => $item['id'],
                            'attempts' => $queue[$key]['attempts'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
            
            // Remove processed items
            $queue = array_filter($queue, fn($item) => !in_array($item['id'], $processedItems));
            $queue = array_values($queue); // Re-index array
            
            $this->saveQueue($queue);
            
        } finally {
            $this->processingQueue = false;
        }
    }

    /**
     * Clean up old queue items
     */
    #[AsPeriodicTask('1 hour')]
    public function cleanupQueue(): void
    {
        try {
            $queue = $this->getQueue();
            $cutoff = time() - $this->maxAge;
            
            $originalCount = count($queue);
            $queue = array_filter($queue, fn($item) => $item['timestamp'] > $cutoff);
            $queue = array_values($queue); // Re-index array
            
            $removedCount = $originalCount - count($queue);
            if ($removedCount > 0) {
                $this->logger->info('Cleaned up expired queue items', [
                    'removed' => $removedCount,
                    'remaining' => count($queue)
                ]);
            }
            
            $this->saveQueue($queue);
        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup queue', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(): array
    {
        $queue = $this->getQueue();
        
        $oldestTimestamp = null;
        if (!empty($queue)) {
            $timestamps = array_column($queue, 'timestamp');
            $oldestTimestamp = min($timestamps);
        }
        
        return [
            'size' => count($queue),
            'oldestTimestamp' => $oldestTimestamp,
            'isOnline' => $this->isOnline(),
            'isProcessing' => $this->processingQueue
        ];
    }

    /**
     * Flush the queue (try to process all items immediately)
     */
    public function flushQueue(?callable $sendFunction = null): void
    {
        if ($this->isOnline() && $sendFunction !== null) {
            $this->processQueue($sendFunction);
        }
    }

    /**
     * Clear the entire queue
     */
    public function clearQueue(): void
    {
        try {
            $cacheItem = $this->cache->getItem(self::QUEUE_KEY);
            if ($cacheItem->isHit()) {
                $this->cache->deleteItem(self::QUEUE_KEY);
                $this->logger->info('Cleared offline queue');
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear queue', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if the system is online
     */
    private function isOnline(): bool
    {
        try {
            $response = $this->httpClient->request('GET', 'https://www.google.com', [
                'timeout' => 5,
                'verify_peer' => false,
                'verify_host' => false
            ]);
            
            return $response->getStatusCode() < 400;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Generate a unique ID
     */
    private function generateId(): string
    {
        return sprintf(
            '%s_%s_%s',
            date('YmdHis'),
            bin2hex(random_bytes(4)),
            uniqid()
        );
    }

    /**
     * Get the queue from cache
     */
    private function getQueue(): array
    {
        try {
            $cacheItem = $this->cache->getItem(self::QUEUE_KEY);
            
            if (!$cacheItem->isHit()) {
                return [];
            }
            
            $queue = $cacheItem->get();
            return is_array($queue) ? $queue : [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get queue from cache', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Save the queue to cache
     */
    private function saveQueue(array $queue): void
    {
        try {
            $cacheItem = $this->cache->getItem(self::QUEUE_KEY);
            $cacheItem->set($queue);
            $cacheItem->expiresAfter($this->maxAge);
            
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save queue to cache', [
                'error' => $e->getMessage(),
                'queueSize' => count($queue)
            ]);
        }
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
        // Clear any remaining items in the queue
        $this->clearQueue();
    }
}