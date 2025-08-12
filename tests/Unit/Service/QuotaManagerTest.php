<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Tests\Unit\Service;

use ErrorExplorer\ErrorReporter\Service\QuotaManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(QuotaManager::class)]
final class QuotaManagerTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private CacheItemInterface&MockObject $cacheItem;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheItem = $this->createMock(CacheItemInterface::class);
    }

    private function createQuotaManager(array $config = []): QuotaManager
    {
        return new QuotaManager(
            cache: $this->cache,
            logger: $this->logger,
            config: $config
        );
    }

    public function testCanSendErrorWithDefaultLimits(): void
    {
        $manager = $this->createQuotaManager();

        // Mock empty cache (first time usage)
        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);

        $result = $manager->canSendError(1000);

        $this->assertTrue($result['allowed']);
        $this->assertArrayHasKey('quotaStats', $result);
    }

    public function testCanSendErrorWithCustomLimits(): void
    {
        $config = [
            'dailyLimit' => 100,
            'monthlyLimit' => 1000,
            'payloadSizeLimit' => 256000,
            'burstLimit' => 5,
            'burstWindowMs' => 30000
        ];

        $manager = $this->createQuotaManager($config);

        // Mock empty cache
        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);

        $result = $manager->canSendError(1000);

        $this->assertTrue($result['allowed']);
        $this->assertArrayHasKey('quotaStats', $result);
    }

    public function testCanSendErrorExceedsPayloadSizeLimit(): void
    {
        $config = ['payloadSizeLimit' => 1000];
        $manager = $this->createQuotaManager($config);

        // Mock empty cache
        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);

        $result = $manager->canSendError(2000);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Payload size (2000 bytes) exceeds limit (1000 bytes)', $result['reason']);
        $this->assertArrayHasKey('quotaStats', $result);
    }

    public function testCanSendErrorExceedsBurstLimit(): void
    {
        $config = ['burstLimit' => 2, 'burstWindowMs' => 60000];
        $manager = $this->createQuotaManager($config);

        $now = time();
        $data = [
            'dailyCount' => 0,
            'monthlyCount' => 0,
            'totalBytes' => 0,
            'burstTimestamps' => [$now - 30, $now - 10], // 2 recent requests
            'lastResetDate' => date('Y-m-d'),
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($data);

        $result = $manager->canSendError(1000);

        $this->assertFalse($result['allowed']);
        $this->assertSame('Burst limit exceeded', $result['reason']);
    }

    public function testCanSendErrorExceedsDailyLimit(): void
    {
        $config = ['dailyLimit' => 10];
        $manager = $this->createQuotaManager($config);

        $data = [
            'dailyCount' => 10,
            'monthlyCount' => 50,
            'totalBytes' => 1000,
            'burstTimestamps' => [],
            'lastResetDate' => date('Y-m-d'),
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($data);

        $result = $manager->canSendError(1000);

        $this->assertFalse($result['allowed']);
        $this->assertSame('Daily quota exceeded', $result['reason']);
    }

    public function testCanSendErrorExceedsMonthlyLimit(): void
    {
        $config = ['monthlyLimit' => 100];
        $manager = $this->createQuotaManager($config);

        $data = [
            'dailyCount' => 5,
            'monthlyCount' => 100,
            'totalBytes' => 10000,
            'burstTimestamps' => [],
            'lastResetDate' => date('Y-m-d'),
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($data);

        $result = $manager->canSendError(1000);

        $this->assertFalse($result['allowed']);
        $this->assertSame('Monthly quota exceeded', $result['reason']);
    }

    public function testRecordUsage(): void
    {
        $manager = $this->createQuotaManager();
        $payloadSize = 5000;

        $initialData = [
            'dailyCount' => 5,
            'monthlyCount' => 50,
            'totalBytes' => 10000,
            'burstTimestamps' => [],
            'lastResetDate' => date('Y-m-d'),
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($initialData);

        $this->cache->expects($this->atLeastOnce())->method('save');

        $this->logger->expects($this->once())->method('info')
            ->with('Quota usage recorded', [
                'payloadSize' => $payloadSize,
                'dailyCount' => 6,
                'monthlyCount' => 51
            ]);

        $manager->recordUsage($payloadSize);
    }

    public function testRecordUsageHandlesCacheException(): void
    {
        $manager = $this->createQuotaManager();
        $payloadSize = 1000;

        $this->cache->method('getItem')->willThrowException(new RuntimeException('Cache error'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $manager->recordUsage($payloadSize);
    }

    public function testRecordUsageCleansBurstTimestamps(): void
    {
        $config = ['burstWindowMs' => 30000]; // 30 seconds
        $manager = $this->createQuotaManager($config);

        $now = time();
        $initialData = [
            'dailyCount' => 5,
            'monthlyCount' => 50,
            'totalBytes' => 10000,
            'burstTimestamps' => [
                $now - 60, // Too old - should be removed
                $now - 20, // Recent - should be kept
                $now - 10  // Recent - should be kept
            ],
            'lastResetDate' => date('Y-m-d'),
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($initialData);

        $this->cache->expects($this->atLeastOnce())->method('save');

        $manager->recordUsage(1000);
    }

    public function testGetStats(): void
    {
        $config = [
            'dailyLimit' => 100,
            'monthlyLimit' => 1000,
            'burstLimit' => 5
        ];
        $manager = $this->createQuotaManager($config);

        $data = [
            'dailyCount' => 25,
            'monthlyCount' => 250,
            'totalBytes' => 50000,
            'burstTimestamps' => [time() - 10, time() - 5], // 2 recent requests
            'lastResetDate' => date('Y-m-d'),
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($data);

        $stats = $manager->getStats();

        $this->assertSame(25, $stats['dailyUsage']);
        $this->assertSame(250, $stats['monthlyUsage']);
        $this->assertSame(75, $stats['dailyRemaining']); // 100 - 25
        $this->assertSame(750, $stats['monthlyRemaining']); // 1000 - 250
        $this->assertSame(2, $stats['burstUsage']);
        $this->assertSame(3, $stats['burstRemaining']); // 5 - 2
        $this->assertSame(50000, $stats['totalBytes']);
        $this->assertFalse($stats['isOverQuota']);
        $this->assertIsInt($stats['nextResetTime']);
    }

    public function testGetStatsWhenOverQuota(): void
    {
        $config = [
            'dailyLimit' => 10,
            'monthlyLimit' => 100,
            'burstLimit' => 2
        ];
        $manager = $this->createQuotaManager($config);

        $data = [
            'dailyCount' => 15, // Over daily limit
            'monthlyCount' => 50,
            'totalBytes' => 10000,
            'burstTimestamps' => [],
            'lastResetDate' => date('Y-m-d'),
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($data);

        $stats = $manager->getStats();

        $this->assertTrue($stats['isOverQuota']);
        $this->assertSame(0, $stats['dailyRemaining']);
    }

    public function testResetQuotas(): void
    {
        $manager = $this->createQuotaManager();

        $this->cache->expects($this->atLeastOnce())->method('save');

        $this->logger->expects($this->once())->method('info')->with('All quotas reset');

        $manager->resetQuotas();
    }

    public function testResetQuotasHandlesCacheException(): void
    {
        $manager = $this->createQuotaManager();

        $this->cache->method('save')->willThrowException(new RuntimeException('Cache error'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $manager->resetQuotas();
    }

    public function testResetDailyQuota(): void
    {
        $manager = $this->createQuotaManager();

        $initialData = [
            'dailyCount' => 50,
            'monthlyCount' => 250,
            'totalBytes' => 10000,
            'burstTimestamps' => [],
            'lastResetDate' => date('Y-m-d', strtotime('-1 day')),
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($initialData);

        $this->cache->expects($this->atLeastOnce())->method('save');

        $this->logger->expects($this->once())->method('info')->with('Daily quota reset');

        $manager->resetDailyQuota();
    }

    public function testResetDailyQuotaHandlesCacheException(): void
    {
        $manager = $this->createQuotaManager();

        $this->cache->method('getItem')->willThrowException(new RuntimeException('Cache error'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $manager->resetDailyQuota();
    }

    public function testCleanupOldDataResetsDailyCountWhenDateChanged(): void
    {
        $manager = $this->createQuotaManager();

        $oldData = [
            'dailyCount' => 50,
            'monthlyCount' => 250,
            'totalBytes' => 10000,
            'burstTimestamps' => [],
            'lastResetDate' => date('Y-m-d', strtotime('-1 day')), // Yesterday
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($oldData);

        $this->cache->expects($this->atLeastOnce())->method('save');

        $result = $manager->canSendError(1000);
        $this->assertTrue($result['allowed']);
    }

    public function testCleanupOldDataResetsMonthlyCountWhenMonthChanged(): void
    {
        $manager = $this->createQuotaManager();

        $lastMonth = date('Y-m', strtotime('-1 month'));
        $oldData = [
            'dailyCount' => 10,
            'monthlyCount' => 500,
            'totalBytes' => 50000,
            'burstTimestamps' => [],
            'lastResetDate' => date('Y-m-d'),
            'lastResetMonth' => $lastMonth // Last month
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($oldData);

        $this->cache->expects($this->atLeastOnce())->method('save');

        $result = $manager->canSendError(1000);
        $this->assertTrue($result['allowed']);
    }

    public function testCleanupOldDataCleansExpiredBurstTimestamps(): void
    {
        $config = ['burstWindowMs' => 30000]; // 30 seconds
        $manager = $this->createQuotaManager($config);

        $now = time();
        $oldData = [
            'dailyCount' => 5,
            'monthlyCount' => 50,
            'totalBytes' => 5000,
            'burstTimestamps' => [
                $now - 60, // Expired
                $now - 45, // Expired
                $now - 10  // Valid
            ],
            'lastResetDate' => date('Y-m-d'),
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($oldData);

        $this->cache->expects($this->atLeastOnce())->method('save');

        $result = $manager->canSendError(1000);
        $this->assertTrue($result['allowed']);
    }

    public function testGetDataHandlesCacheException(): void
    {
        $manager = $this->createQuotaManager();

        $this->cache->method('getItem')->willThrowException(new RuntimeException('Cache error'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        // Should return default data when cache fails
        $result = $manager->canSendError(1000);
        $this->assertTrue($result['allowed']);
    }

    public function testSaveDataHandlesCacheException(): void
    {
        $manager = $this->createQuotaManager();

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('save')->willThrowException(new RuntimeException('Cache save error'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $manager->recordUsage(1000);
    }

    public function testUpdateConfig(): void
    {
        $manager = $this->createQuotaManager(['dailyLimit' => 100]);

        $updates = [
            'dailyLimit' => 200,
            'monthlyLimit' => 2000
        ];

        $this->logger->expects($this->once())->method('info')
            ->with('Quota configuration updated', $this->arrayHasKey('config'));

        $manager->updateConfig($updates);

        // Verify new limits are applied
        $config = $manager->getConfig();
        $this->assertSame(200, $config['dailyLimit']);
        $this->assertSame(2000, $config['monthlyLimit']);
    }

    public function testGetConfig(): void
    {
        $config = [
            'dailyLimit' => 500,
            'monthlyLimit' => 5000,
            'payloadSizeLimit' => 1024000
        ];

        $manager = $this->createQuotaManager($config);

        $returnedConfig = $manager->getConfig();

        $this->assertSame(500, $returnedConfig['dailyLimit']);
        $this->assertSame(5000, $returnedConfig['monthlyLimit']);
        $this->assertSame(1024000, $returnedConfig['payloadSizeLimit']);
    }

    #[DataProvider('burstLimitProvider')]
    public function testBurstLimitCalculation(array $timestamps, int $windowMs, int $expectedCount): void
    {
        $config = ['burstWindowMs' => $windowMs];
        $manager = $this->createQuotaManager($config);

        $data = [
            'dailyCount' => 0,
            'monthlyCount' => 0,
            'totalBytes' => 0,
            'burstTimestamps' => $timestamps,
            'lastResetDate' => date('Y-m-d'),
            'lastResetMonth' => date('Y-m')
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($data);

        $stats = $manager->getStats();

        $this->assertSame($expectedCount, $stats['burstUsage']);
    }

    public static function burstLimitProvider(): array
    {
        $now = time();

        return [
            'all recent' => [
                [$now - 10, $now - 5, $now - 2],
                30000, // 30 seconds window
                3      // All 3 should count
            ],
            'mixed old and new' => [
                [$now - 60, $now - 10, $now - 5],
                30000, // 30 seconds window
                2      // Only 2 recent ones should count
            ],
            'all old' => [
                [$now - 120, $now - 90, $now - 60],
                30000, // 30 seconds window
                0      // None should count
            ],
            'empty timestamps' => [
                [],
                30000, // 30 seconds window
                0      // None should count
            ],
        ];
    }

    public function testDefaultConfigurationValues(): void
    {
        $manager = $this->createQuotaManager();
        $config = $manager->getConfig();

        $this->assertSame(1000, $config['dailyLimit']);
        $this->assertSame(10000, $config['monthlyLimit']);
        $this->assertSame(512000, $config['payloadSizeLimit']);
        $this->assertSame(10, $config['burstLimit']);
        $this->assertSame(60000, $config['burstWindowMs']);
    }
}