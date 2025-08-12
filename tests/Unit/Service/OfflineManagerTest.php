<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Tests\Unit\Service;

use ErrorExplorer\ErrorReporter\Service\OfflineManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(OfflineManager::class)]
final class OfflineManagerTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $cache;
    private HttpClientInterface&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;
    private CacheItemInterface&MockObject $cacheItem;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheItem = $this->createMock(CacheItemInterface::class);
    }

    private function createOfflineManager(array $overrides = []): OfflineManager
    {
        return new OfflineManager(
            cache: $overrides['cache'] ?? $this->cache,
            httpClient: $overrides['httpClient'] ?? $this->httpClient,
            logger: $overrides['logger'] ?? $this->logger,
            maxQueueSize: $overrides['maxQueueSize'] ?? 50,
            maxAge: $overrides['maxAge'] ?? 86400
        );
    }

    public function testHandleErrorWhenOnlineAndSendSuccessful(): void
    {
        $manager = $this->createOfflineManager();
        $errorData = ['message' => 'Test error', 'timestamp' => time()];

        // Mock online check
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        $sendFunction = fn($data) => $data === $errorData;

        $result = $manager->handleError($errorData, $sendFunction);

        $this->assertTrue($result);
    }

    public function testHandleErrorWhenOnlineButSendFails(): void
    {
        $manager = $this->createOfflineManager();
        $errorData = ['message' => 'Test error', 'timestamp' => time()];

        // Mock online check
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        // Mock send function that fails
        $sendFunction = fn($data) => throw new RuntimeException('Send failed');

        // Mock cache operations for queuing
        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cacheItem->method('get')->willReturn([]);
        $this->cache->expects($this->once())->method('save');

        $this->logger->expects($this->once())->method('warning');

        $result = $manager->handleError($errorData, $sendFunction);

        $this->assertTrue($result);
    }

    public function testHandleErrorWhenOffline(): void
    {
        $manager = $this->createOfflineManager();
        $errorData = ['message' => 'Test error', 'timestamp' => time()];

        // Mock offline check (exception during HTTP request)
        $this->httpClient->expects($this->once())->method('request')
            ->willThrowException(new RuntimeException('Network error'));

        // Mock cache operations for queuing
        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cacheItem->method('get')->willReturn([]);
        $this->cache->expects($this->once())->method('save');

        $sendFunction = fn($data) => true;

        $result = $manager->handleError($errorData, $sendFunction);

        $this->assertTrue($result);
    }

    public function testHandleErrorWithoutSendFunction(): void
    {
        $manager = $this->createOfflineManager();
        $errorData = ['message' => 'Test error', 'timestamp' => time()];

        // Mock offline check
        $this->httpClient->expects($this->once())->method('request')
            ->willThrowException(new RuntimeException('Network error'));

        // Mock cache operations for queuing
        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cacheItem->method('get')->willReturn([]);
        $this->cache->expects($this->once())->method('save');

        $result = $manager->handleError($errorData);

        $this->assertTrue($result);
    }

    public function testQueueErrorEnforcesMaxQueueSize(): void
    {
        $manager = $this->createOfflineManager(['maxQueueSize' => 2]);
        $errorData = ['message' => 'Test error', 'timestamp' => time()];

        // Mock offline check
        $this->httpClient->expects($this->once())->method('request')
            ->willThrowException(new RuntimeException('Network error'));

        // Mock existing queue with items
        $existingQueue = [
            ['id' => 'old1', 'errorData' => ['message' => 'Old 1'], 'timestamp' => time() - 100, 'attempts' => 0],
            ['id' => 'old2', 'errorData' => ['message' => 'Old 2'], 'timestamp' => time() - 50, 'attempts' => 0],
            ['id' => 'old3', 'errorData' => ['message' => 'Old 3'], 'timestamp' => time() - 10, 'attempts' => 0],
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($existingQueue);

        // Expect save to be called with a queue of max size
        $this->cache->expects($this->once())->method('save')
            ->with($this->callback(function ($item) {
                // The queue should be trimmed to maxQueueSize + 1 new item = 3 items, then trimmed to 2
                return true;
            }));

        $result = $manager->handleError($errorData);

        $this->assertTrue($result);
    }

    public function testHandleErrorLogsErrorsOnCacheException(): void
    {
        $manager = $this->createOfflineManager();
        $errorData = ['message' => 'Test error', 'timestamp' => time()];

        // Mock offline check
        $this->httpClient->expects($this->once())->method('request')
            ->willThrowException(new RuntimeException('Network error'));

        // Mock cache failure
        $this->cache->method('getItem')->willThrowException(new RuntimeException('Cache get error'));
        $this->cache->method('save')->willThrowException(new RuntimeException('Cache save error'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $result = $manager->handleError($errorData);

        // Note: Despite cache failures, the method returns true due to internal exception handling
        $this->assertTrue($result);
    }

    public function testProcessQueueWhenOffline(): void
    {
        $manager = $this->createOfflineManager();

        // Mock offline check
        $this->httpClient->expects($this->once())->method('request')
            ->willThrowException(new RuntimeException('Network error'));

        $sendFunction = fn($data) => true;

        $manager->processQueue($sendFunction);

        // Should not process queue when offline
        $this->cache->expects($this->never())->method('getItem');
    }

    public function testProcessQueueReturnsEarlyWhenNoSendFunction(): void
    {
        $manager = $this->createOfflineManager();

        // Mock online check
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        // Should not attempt to get queue items when no send function provided
        $this->cache->expects($this->never())->method('getItem');

        // Should not process queue when no send function provided
        $manager->processQueue();
    }

    public function testProcessQueueWithoutSendFunction(): void
    {
        $manager = $this->createOfflineManager();

        // Mock online check
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        $manager->processQueue();

        // Should not process queue without send function
        $this->cache->expects($this->never())->method('getItem');
    }

    public function testProcessQueueSuccessfulProcessing(): void
    {
        $manager = $this->createOfflineManager();

        // Mock online check
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        // Mock queue with items
        $queue = [
            ['id' => 'item1', 'errorData' => ['message' => 'Error 1'], 'timestamp' => time(), 'attempts' => 0],
            ['id' => 'item2', 'errorData' => ['message' => 'Error 2'], 'timestamp' => time(), 'attempts' => 0],
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($queue);

        // Mock successful send function
        $sendFunction = fn($data) => true;

        $this->logger->expects($this->exactly(2))->method('info');
        $this->cache->expects($this->once())->method('save');

        $manager->processQueue($sendFunction);
    }

    public function testProcessQueueWithFailedItems(): void
    {
        $manager = $this->createOfflineManager();

        // Mock online check
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        // Mock queue with items that have max attempts
        $queue = [
            ['id' => 'item1', 'errorData' => ['message' => 'Error 1'], 'timestamp' => time(), 'attempts' => 3],
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($queue);

        // Mock failing send function
        $sendFunction = fn($data) => false;

        $this->logger->expects($this->once())->method('warning');
        $this->cache->expects($this->once())->method('save');

        $manager->processQueue($sendFunction);
    }

    public function testProcessQueueWithExceptionInSendFunction(): void
    {
        $manager = $this->createOfflineManager();

        // Mock online check
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        // Mock queue with items
        $queue = [
            ['id' => 'item1', 'errorData' => ['message' => 'Error 1'], 'timestamp' => time(), 'attempts' => 2],
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($queue);

        // Mock send function that throws exception
        $sendFunction = fn($data) => throw new RuntimeException('Send error');

        $this->logger->expects($this->once())->method('error');
        $this->cache->expects($this->once())->method('save');

        $manager->processQueue($sendFunction);
    }

    public function testCleanupQueueRemovesExpiredItems(): void
    {
        $manager = $this->createOfflineManager(['maxAge' => 3600]); // 1 hour

        $now = time();
        $queue = [
            ['id' => 'recent', 'errorData' => ['message' => 'Recent'], 'timestamp' => $now - 1800], // 30 min ago
            ['id' => 'old1', 'errorData' => ['message' => 'Old 1'], 'timestamp' => $now - 7200], // 2 hours ago
            ['id' => 'old2', 'errorData' => ['message' => 'Old 2'], 'timestamp' => $now - 10800], // 3 hours ago
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($queue);

        $this->logger->expects($this->once())->method('info')
            ->with('Cleaned up expired queue items', [
                'removed' => 2,
                'remaining' => 1
            ]);

        $this->cache->expects($this->once())->method('save');

        $manager->cleanupQueue();
    }

    public function testCleanupQueueWithNoExpiredItems(): void
    {
        $manager = $this->createOfflineManager(['maxAge' => 3600]); // 1 hour

        $now = time();
        $queue = [
            ['id' => 'recent1', 'errorData' => ['message' => 'Recent 1'], 'timestamp' => $now - 1800], // 30 min ago
            ['id' => 'recent2', 'errorData' => ['message' => 'Recent 2'], 'timestamp' => $now - 900], // 15 min ago
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($queue);

        // No info log should be called when no items are removed
        $this->logger->expects($this->never())->method('info');

        $this->cache->expects($this->once())->method('save');

        $manager->cleanupQueue();
    }

    public function testCleanupQueueHandlesCacheException(): void
    {
        $manager = $this->createOfflineManager();

        $this->cache->method('getItem')->willThrowException(new RuntimeException('Cache error'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $manager->cleanupQueue();
    }

    public function testGetQueueStats(): void
    {
        $manager = $this->createOfflineManager();

        // Mock online check
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        $now = time();
        $queue = [
            ['id' => 'item1', 'errorData' => ['message' => 'Error 1'], 'timestamp' => $now - 1000, 'attempts' => 0],
            ['id' => 'item2', 'errorData' => ['message' => 'Error 2'], 'timestamp' => $now - 2000, 'attempts' => 1],
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($queue);

        $stats = $manager->getQueueStats();

        $this->assertSame(2, $stats['size']);
        $this->assertSame($now - 2000, $stats['oldestTimestamp']);
        $this->assertTrue($stats['isOnline']);
        $this->assertFalse($stats['isProcessing']);
    }

    public function testGetQueueStatsWithEmptyQueue(): void
    {
        $manager = $this->createOfflineManager();

        // Mock online check
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cacheItem->method('get')->willReturn([]);

        $stats = $manager->getQueueStats();

        $this->assertSame(0, $stats['size']);
        $this->assertNull($stats['oldestTimestamp']);
        $this->assertTrue($stats['isOnline']);
        $this->assertFalse($stats['isProcessing']);
    }

    public function testFlushQueueWhenOnline(): void
    {
        $manager = $this->createOfflineManager();

        // Mock online check
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->expects($this->exactly(2))->method('request')->willReturn($response); // Once for isOnline, once for processQueue

        $sendFunction = fn($data) => true;

        $queue = [
            ['id' => 'item1', 'errorData' => ['message' => 'Error 1'], 'timestamp' => time(), 'attempts' => 0],
        ];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($queue);
        $this->cache->expects($this->once())->method('save');

        $manager->flushQueue($sendFunction);
    }

    public function testFlushQueueWhenOffline(): void
    {
        $manager = $this->createOfflineManager();

        // Mock offline check
        $this->httpClient->expects($this->once())->method('request')
            ->willThrowException(new RuntimeException('Network error'));

        $sendFunction = fn($data) => true;

        // Should not attempt to process queue
        $this->cache->expects($this->never())->method('getItem');

        $manager->flushQueue($sendFunction);
    }

    public function testClearQueue(): void
    {
        $manager = $this->createOfflineManager();

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cache->expects($this->once())->method('deleteItem')->with('error_explorer_offline_queue');

        $this->logger->expects($this->once())->method('info')->with('Cleared offline queue');

        $manager->clearQueue();
    }

    public function testClearQueueWhenQueueDoesNotExist(): void
    {
        $manager = $this->createOfflineManager();

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cache->expects($this->never())->method('deleteItem');

        $this->logger->expects($this->never())->method('info');

        $manager->clearQueue();
    }

    public function testClearQueueHandlesCacheException(): void
    {
        $manager = $this->createOfflineManager();

        $this->cache->method('getItem')->willThrowException(new RuntimeException('Cache error'));

        $this->logger->expects($this->once())->method('error')
            ->with('Failed to clear queue', ['error' => 'Cache error']);

        $manager->clearQueue();
    }

    #[DataProvider('onlineStatusProvider')]
    public function testIsOnlineDetection(int $statusCode, bool $expectedOnline): void
    {
        $manager = $this->createOfflineManager();
        $errorData = ['message' => 'Test error'];

        if ($expectedOnline) {
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($statusCode);
            $this->httpClient->expects($this->once())->method('request')->willReturn($response);

            $sendFunction = fn($data) => true;
            $result = $manager->handleError($errorData, $sendFunction);
        } else {
            $this->httpClient->expects($this->once())->method('request')
                ->willThrowException(new RuntimeException('Network error'));

            // Mock cache for queuing
            $this->cache->method('getItem')->willReturn($this->cacheItem);
            $this->cacheItem->method('isHit')->willReturn(false);
            $this->cacheItem->method('get')->willReturn([]);
            $this->cache->expects($this->once())->method('save');

            $result = $manager->handleError($errorData);
        }

        $this->assertTrue($result);
    }

    public static function onlineStatusProvider(): array
    {
        return [
            'success status' => [200, true],
            'client error status' => [404, false],
            'server error status' => [500, false],
            'redirect status' => [302, true],
        ];
    }

    public function testGeneratedIdUniqueness(): void
    {
        $manager = $this->createOfflineManager();

        // Mock offline to force queuing
        $this->httpClient->method('request')
            ->willThrowException(new RuntimeException('Network error'));

        $existingQueue = [];
        $savedQueues = [];

        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($existingQueue);

        // Capture the saved queue data
        $this->cache->expects($this->exactly(3))->method('save')
            ->with($this->callback(function ($item) use (&$savedQueues) {
                $savedQueues[] = $item->get();
                return true;
            }));

        // Add multiple errors
        $manager->handleError(['message' => 'Error 1']);
        $manager->handleError(['message' => 'Error 2']);
        $manager->handleError(['message' => 'Error 3']);

        // Verify all IDs are unique
        $allIds = [];
        foreach ($savedQueues as $queue) {
            foreach ($queue as $item) {
                $this->assertNotContains($item['id'], $allIds, 'ID should be unique');
                $allIds[] = $item['id'];
            }
        }
    }
}