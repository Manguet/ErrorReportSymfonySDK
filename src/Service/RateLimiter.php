<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Rate limiter to prevent overwhelming the API and handle duplicate errors
 */
class RateLimiter
{
    private const REQUESTS_KEY = 'error_explorer_rate_limit_requests';
    private const ERRORS_KEY = 'error_explorer_rate_limit_errors';
    private array $config;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        array $config = []
    ) {
        $this->config = array_merge([
            'maxRequests' => 100,
            'windowMs' => 60000, // 1 minute in milliseconds
            'duplicateErrorWindow' => 300000 // 5 minutes in milliseconds
        ], $config);
    }

    /**
     * Check if an error can be sent based on rate limits and duplication
     */
    public function canSendError(array $errorData): array
    {
        $now = time() * 1000; // Convert to milliseconds for consistency

        // Clean up old requests first
        $this->removeExpiredRequests($now);

        // Check rate limit
        $requests = $this->getRequests();
        if (count($requests) >= $this->config['maxRequests']) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'resetTime' => $this->getNextResetTime($now),
                'reason' => 'Rate limit exceeded'
            ];
        }

        // Check for duplicate errors
        $errorHash = $this->generateErrorHash($errorData);
        $lastSeen = $this->getLastErrorTime($errorHash);

        if ($lastSeen !== null && ($now - $lastSeen) < $this->config['duplicateErrorWindow']) {
            return [
                'allowed' => false,
                'remaining' => $this->config['maxRequests'] - count($requests),
                'resetTime' => $this->getNextResetTime($now),
                'reason' => 'Duplicate error'
            ];
        }

        return [
            'allowed' => true,
            'remaining' => $this->config['maxRequests'] - count($requests) - 1,
            'resetTime' => $this->getNextResetTime($now)
        ];
    }

    /**
     * Mark that an error was sent
     */
    public function markErrorSent(array $errorData): void
    {
        try {
            $now = time() * 1000;

            // Add to requests list
            $requests = $this->getRequests();
            $requests[] = $now;
            $this->saveRequests($requests);

            // Update error hash tracking
            $errorHash = $this->generateErrorHash($errorData);
            $this->setLastErrorTime($errorHash, $now);

            $this->logger->debug('Error marked as sent for rate limiting', [
                'errorHash' => $errorHash,
                'requestCount' => count($requests)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark error as sent', [
                'error' => $e->getMessage(),
                'errorData' => $errorData
            ]);
        }
    }

    /**
     * Generate a hash for error deduplication
     */
    private function generateErrorHash(array $errorData): string
    {
        // Create hash based on message, file, and line
        $key = sprintf(
            '%s-%s-%s',
            $errorData['message'] ?? '',
            $errorData['file'] ?? '',
            $errorData['line'] ?? ''
        );
        
        return substr(hash('sha256', $key), 0, 32);
    }

    /**
     * Remove expired requests from tracking
     */
    private function removeExpiredRequests(int $now): void
    {
        try {
            $cutoff = $now - $this->config['windowMs'];
            $requests = $this->getRequests();

            $requests = array_values(array_filter($requests, fn($timestamp) => $timestamp > $cutoff));

            $this->saveRequests($requests);
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove expired requests', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get the next reset time for rate limiting
     */
    private function getNextResetTime(int $now): int
    {
        $requests = $this->getRequests();
        
        if (empty($requests)) {
            return $now + $this->config['windowMs'];
        }

        $oldestRequest = min($requests);
        return $oldestRequest + $this->config['windowMs'];
    }

    /**
     * Cleanup old data
     */
    #[AsPeriodicTask('1 hour')]
    public function cleanup(): void
    {
        try {
            $now = time() * 1000;
            
            // Clean up old requests
            $this->removeExpiredRequests($now);
            
            // Clean up old error hashes
            $errorHashes = $this->getErrorHashes();
            $cutoff = $now - $this->config['duplicateErrorWindow'];
            
            $cleanedHashes = array_filter($errorHashes, fn($timestamp) => $timestamp > $cutoff);
            
            $this->saveErrorHashes($cleanedHashes);
            
            $this->logger->info('Rate limiter cleanup completed');
        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup rate limiter data', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get rate limiter statistics
     */
    public function getStats(): array
    {
        try {
            $requests = $this->getRequests();
            $errorHashes = $this->getErrorHashes();

            return [
                'requestCount' => count($requests),
                'errorHashCount' => count($errorHashes),
                'remaining' => max(0, $this->config['maxRequests'] - count($requests)),
                'windowMs' => $this->config['windowMs'],
                'duplicateWindow' => $this->config['duplicateErrorWindow']
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get rate limiter stats', ['error' => $e->getMessage()]);
            return [
                'requestCount' => 0,
                'errorHashCount' => 0,
                'remaining' => $this->config['maxRequests'],
                'windowMs' => $this->config['windowMs'],
                'duplicateWindow' => $this->config['duplicateErrorWindow']
            ];
        }
    }

    /**
     * Get tracked requests from storage
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
     * Save tracked requests to storage
     */
    private function saveRequests(array $requests): void
    {
        try {
            $cacheItem = $this->cache->getItem(self::REQUESTS_KEY);
            $cacheItem->set($requests);
            // Store for twice the window duration to handle edge cases
            $cacheItem->expiresAfter((int) (($this->config['windowMs'] * 2) / 1000));
            
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save requests to cache', [
                'error' => $e->getMessage(),
                'requestCount' => count($requests)
            ]);
        }
    }

    /**
     * Get error hashes from storage
     */
    private function getErrorHashes(): array
    {
        try {
            $cacheItem = $this->cache->getItem(self::ERRORS_KEY);
            
            if (!$cacheItem->isHit()) {
                return [];
            }
            
            $hashes = $cacheItem->get();
            return is_array($hashes) ? $hashes : [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get error hashes from cache', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Save error hashes to storage
     */
    private function saveErrorHashes(array $hashes): void
    {
        try {
            $cacheItem = $this->cache->getItem(self::ERRORS_KEY);
            $cacheItem->set($hashes);
            // Store for twice the duplicate window duration
            $cacheItem->expiresAfter((int) (($this->config['duplicateErrorWindow'] * 2) / 1000));
            
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save error hashes to cache', [
                'error' => $e->getMessage(),
                'hashCount' => count($hashes)
            ]);
        }
    }

    /**
     * Get the last time an error hash was seen
     */
    private function getLastErrorTime(string $hash): ?int
    {
        $hashes = $this->getErrorHashes();
        return $hashes[$hash] ?? null;
    }

    /**
     * Set the last seen time for an error hash
     */
    private function setLastErrorTime(string $hash, int $timestamp): void
    {
        $hashes = $this->getErrorHashes();
        $hashes[$hash] = $timestamp;
        $this->saveErrorHashes($hashes);
    }

    /**
     * Update rate limiter configuration
     */
    public function updateConfig(array $updates): void
    {
        $this->config = array_merge($this->config, $updates);
        $this->logger->info('Rate limiter configuration updated', ['config' => $this->config]);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Check if a request is allowed based on rate limits
     */
    public function isAllowed(string $errorHash): bool
    {
        $result = $this->canSendError(['hash' => $errorHash]);
        return $result['allowed'];
    }

    /**
     * Record a request for rate limiting
     */
    public function recordRequest(string $errorHash): void
    {
        $now = time() * 1000;
        
        // Add to requests
        $requests = $this->getRequests();
        $requests[] = $now;
        $this->saveRequests($requests);
        
        // Track error hash to prevent duplicates
        $this->setLastErrorTime($errorHash, $now);
        
        $this->logger->debug('Request recorded for rate limiting', ['hash' => $errorHash]);
    }


    /**
     * Reset all rate limiting data
     */
    public function reset(): void
    {
        try {
            $this->cache->deleteItem(self::REQUESTS_KEY);
            $this->cache->deleteItem(self::ERRORS_KEY);
            
            $this->logger->info('Rate limiter data reset');
        } catch (\Exception $e) {
            $this->logger->error('Failed to reset rate limiter data', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clean up resources
     */
    public function destroy(): void
    {
        // Perform final cleanup
        $this->cleanup();
        $this->logger->debug('RateLimiter resources cleaned up');
    }
}