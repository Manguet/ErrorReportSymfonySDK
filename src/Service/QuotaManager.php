<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Manages error reporting quotas to prevent abuse and control costs
 */
class QuotaManager
{
    private const QUOTA_DATA_KEY = 'error_explorer_quota_data';
    private array $config;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        array $config = []
    ) {
        $this->config = array_merge([
            'dailyLimit' => 1000,
            'monthlyLimit' => 10000,
            'payloadSizeLimit' => 512000, // 500KB
            'burstLimit' => 10,
            'burstWindowMs' => 60000 // 1 minute
        ], $config);
    }

    /**
     * Check if an error can be sent based on quotas
     */
    public function canSendError(int $payloadSize = 0): array
    {
        $this->cleanupOldData();
        $stats = $this->getStats();
        
        // Check payload size limit
        if ($payloadSize > $this->config['payloadSizeLimit']) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Payload size (%d bytes) exceeds limit (%d bytes)',
                    $payloadSize,
                    $this->config['payloadSizeLimit']
                ),
                'quotaStats' => $stats
            ];
        }
        
        // Check burst limit
        $burstCount = $this->getBurstCount();
        if ($burstCount >= $this->config['burstLimit']) {
            return [
                'allowed' => false,
                'reason' => 'Burst limit exceeded',
                'quotaStats' => $stats
            ];
        }
        
        // Check daily limit
        $data = $this->getData();
        if ($data['dailyCount'] >= $this->config['dailyLimit']) {
            return [
                'allowed' => false,
                'reason' => 'Daily quota exceeded',
                'quotaStats' => $stats
            ];
        }
        
        // Check monthly limit
        if ($data['monthlyCount'] >= $this->config['monthlyLimit']) {
            return [
                'allowed' => false,
                'reason' => 'Monthly quota exceeded',
                'quotaStats' => $stats
            ];
        }
        
        return [
            'allowed' => true,
            'quotaStats' => $stats
        ];
    }

    /**
     * Record usage of quota
     */
    public function recordUsage(int $payloadSize = 0): void
    {
        try {
            $this->cleanupOldData();
            
            $data = $this->getData();
            $now = time();
            
            // Increment counters
            $data['dailyCount']++;
            $data['monthlyCount']++;
            $data['totalBytes'] += $payloadSize;
            
            // Add to burst tracking
            $data['burstTimestamps'][] = $now;
            
            // Keep only recent burst timestamps
            $cutoff = $now - ($this->config['burstWindowMs'] / 1000);
            $data['burstTimestamps'] = array_values(array_filter(
                $data['burstTimestamps'],
                fn($timestamp) => $timestamp > $cutoff
            ));
            
            $this->saveData($data);
            
            $this->logger->info('Quota usage recorded', [
                'payloadSize' => $payloadSize,
                'dailyCount' => $data['dailyCount'],
                'monthlyCount' => $data['monthlyCount']
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to record quota usage', [
                'error' => $e->getMessage(),
                'payloadSize' => $payloadSize
            ]);
        }
    }

    /**
     * Get current quota statistics
     */
    public function getStats(): array
    {
        $this->cleanupOldData();
        
        $data = $this->getData();
        $burstCount = $this->getBurstCount();
        
        // Calculate next reset time (midnight)
        $tomorrow = mktime(0, 0, 0, (int)date('n'), (int)date('j') + 1, (int)date('Y'));
        
        return [
            'dailyUsage' => $data['dailyCount'],
            'monthlyUsage' => $data['monthlyCount'],
            'dailyRemaining' => max(0, $this->config['dailyLimit'] - $data['dailyCount']),
            'monthlyRemaining' => max(0, $this->config['monthlyLimit'] - $data['monthlyCount']),
            'burstUsage' => $burstCount,
            'burstRemaining' => max(0, $this->config['burstLimit'] - $burstCount),
            'totalBytes' => $data['totalBytes'],
            'isOverQuota' => $data['dailyCount'] >= $this->config['dailyLimit'] || 
                            $data['monthlyCount'] >= $this->config['monthlyLimit'] ||
                            $burstCount >= $this->config['burstLimit'],
            'nextResetTime' => $tomorrow
        ];
    }

    /**
     * Reset all quotas
     */
    public function resetQuotas(): void
    {
        try {
            $this->saveData([
                'dailyCount' => 0,
                'monthlyCount' => 0,
                'totalBytes' => 0,
                'burstTimestamps' => [],
                'lastResetDate' => $this->getDateKey(),
                'lastResetMonth' => $this->getMonthKey()
            ]);
            
            $this->logger->info('All quotas reset');
        } catch (\Exception $e) {
            $this->logger->error('Failed to reset quotas', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Reset daily quota
     */
    #[AsPeriodicTask('0 0 * * *')] // Daily at midnight
    public function resetDailyQuota(): void
    {
        try {
            $data = $this->getData();
            $data['dailyCount'] = 0;
            $data['lastResetDate'] = $this->getDateKey();
            $this->saveData($data);
            
            $this->logger->info('Daily quota reset');
        } catch (\Exception $e) {
            $this->logger->error('Failed to reset daily quota', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clean up old data and reset counters if needed
     */
    private function cleanupOldData(): void
    {
        try {
            $data = $this->getData();
            $currentDate = $this->getDateKey();
            $currentMonth = $this->getMonthKey();
            
            // Reset daily count if date changed
            if ($currentDate !== $data['lastResetDate']) {
                $data['dailyCount'] = 0;
                $data['lastResetDate'] = $currentDate;
            }
            
            // Reset monthly count if month changed
            if ($currentMonth !== $data['lastResetMonth']) {
                $data['monthlyCount'] = 0;
                $data['totalBytes'] = 0;
                $data['lastResetMonth'] = $currentMonth;
            }
            
            // Clean up old burst timestamps
            $now = time();
            $cutoff = $now - ($this->config['burstWindowMs'] / 1000);
            $data['burstTimestamps'] = array_values(array_filter(
                $data['burstTimestamps'],
                fn($timestamp) => $timestamp > $cutoff
            ));
            
            $this->saveData($data);
        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup old data', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get current burst count
     */
    private function getBurstCount(): int
    {
        $data = $this->getData();
        $now = time();
        $cutoff = $now - ($this->config['burstWindowMs'] / 1000);
        
        return count(array_filter(
            $data['burstTimestamps'],
            fn($timestamp) => $timestamp > $cutoff
        ));
    }

    /**
     * Get stored quota data
     */
    private function getData(): array
    {
        try {
            $cacheItem = $this->cache->getItem(self::QUOTA_DATA_KEY);
            
            if (!$cacheItem->isHit()) {
                return $this->getDefaultData();
            }
            
            $data = $cacheItem->get();
            return array_merge($this->getDefaultData(), is_array($data) ? $data : []);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get quota data from cache', ['error' => $e->getMessage()]);
            return $this->getDefaultData();
        }
    }

    /**
     * Get default data structure
     */
    private function getDefaultData(): array
    {
        return [
            'dailyCount' => 0,
            'monthlyCount' => 0,
            'totalBytes' => 0,
            'burstTimestamps' => [],
            'lastResetDate' => $this->getDateKey(),
            'lastResetMonth' => $this->getMonthKey()
        ];
    }

    /**
     * Save quota data
     */
    private function saveData(array $data): void
    {
        try {
            $cacheItem = $this->cache->getItem(self::QUOTA_DATA_KEY);
            $cacheItem->set($data);
            $cacheItem->expiresAfter(86400 * 32); // 32 days
            
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save quota data to cache', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    /**
     * Get current date key
     */
    private function getDateKey(): string
    {
        return date('Y-m-d');
    }

    /**
     * Get current month key
     */
    private function getMonthKey(): string
    {
        return date('Y-m');
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $updates): void
    {
        $this->config = array_merge($this->config, $updates);
        $this->logger->info('Quota configuration updated', ['config' => $this->config]);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Clean up resources
     */
    public function destroy(): void
    {
        // Nothing specific to clean up for quota manager
        $this->logger->debug('QuotaManager resources cleaned up');
    }
}