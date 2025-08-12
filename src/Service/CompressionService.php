<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use Psr\Log\LoggerInterface;

/**
 * Handles compression of error data to reduce bandwidth usage
 */
readonly class CompressionService
{
    public function __construct(
        private LoggerInterface $logger,
        private array $config = []
    ) {
        $this->config = array_merge([
            'enabled' => true,
            'threshold' => 1024, // 1KB - minimum size to compress
            'level' => 6, // Compression level (1-9)
            'method' => 'gzip' // Currently only gzip supported
        ], $config);
    }

    /**
     * Compress data if conditions are met
     */
    public function compress(string $data): array
    {
        $originalSize = strlen($data);

        if (!$this->config['enabled'] || $originalSize < $this->config['threshold']) {
            return [
                'data' => $data,
                'compressed' => false,
                'originalSize' => $originalSize,
                'compressedSize' => $originalSize,
                'compressionRatio' => 1.0
            ];
        }

        // Check if gzip functions are available
        if (!function_exists('gzencode')) {
            $this->logger->warning('gzip functions not available for compression');
            return [
                'data' => $data,
                'compressed' => false,
                'originalSize' => $originalSize,
                'compressedSize' => $originalSize,
                'compressionRatio' => 1.0,
                'error' => 'gzip functions not available'
            ];
        }

        try {
            // Compress using gzip
            $compressed = gzencode($data, $this->config['level']);
            
            if ($compressed === false) {
                throw new \RuntimeException('Compression failed');
            }
            
            $compressedSize = strlen($compressed);
            $compressionRatio = $compressedSize / $originalSize;
            
            // Only use compression if it actually reduces size
            if ($compressedSize >= $originalSize) {
                $this->logger->debug('Compression did not reduce size', [
                    'originalSize' => $originalSize,
                    'compressedSize' => $compressedSize
                ]);

                return [
                    'data' => $data,
                    'compressed' => false,
                    'originalSize' => $originalSize,
                    'compressedSize' => $originalSize,
                    'compressionRatio' => 1.0,
                    'reason' => 'Compression did not reduce size'
                ];
            }

            $this->logger->debug('Data compressed successfully', [
                'originalSize' => $originalSize,
                'compressedSize' => $compressedSize,
                'compressionRatio' => $compressionRatio,
                'savings' => $originalSize - $compressedSize
            ]);

            return [
                'data' => base64_encode($compressed),
                'compressed' => true,
                'originalSize' => $originalSize,
                'compressedSize' => $compressedSize,
                'compressionRatio' => $compressionRatio,
                'encoding' => 'gzip+base64'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Compression failed', [
                'error' => $e->getMessage(),
                'originalSize' => $originalSize
            ]);

            return [
                'data' => $data,
                'compressed' => false,
                'originalSize' => $originalSize,
                'compressedSize' => $originalSize,
                'compressionRatio' => 1.0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Decompress data
     */
    public function decompress(string $data, string $encoding = 'gzip+base64'): array
    {
        try {
            if ($encoding === 'gzip+base64') {
                // First decode base64
                $decoded = base64_decode($data, true);
                if ($decoded === false) {
                    throw new \InvalidArgumentException('Base64 decode failed');
                }
                
                // Then decompress gzip
                $decompressed = gzdecode($decoded);
                if ($decompressed === false) {
                    throw new \RuntimeException('Gzip decompression failed');
                }
                
                $this->logger->debug('Data decompressed successfully', [
                    'originalSize' => strlen($data),
                    'decompressedSize' => strlen($decompressed)
                ]);
                
                return [
                    'data' => $decompressed,
                    'success' => true,
                    'originalSize' => strlen($data),
                    'decompressedSize' => strlen($decompressed)
                ];
            }
            
            // If not compressed or unknown encoding, return as-is
            return [
                'data' => $data,
                'success' => true,
                'originalSize' => strlen($data),
                'decompressedSize' => strlen($data),
                'note' => 'Data was not compressed'
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Decompression failed', [
                'error' => $e->getMessage(),
                'encoding' => $encoding,
                'dataLength' => strlen($data)
            ]);

            return [
                'data' => $data,
                'success' => false,
                'error' => $e->getMessage(),
                'originalSize' => strlen($data),
                'decompressedSize' => strlen($data)
            ];
        }
    }

    /**
     * Get compression headers for HTTP requests
     */
    public function getCompressionHeaders(bool $isCompressed): array
    {
        if (!$isCompressed) {
            return [];
        }

        return [
            'Content-Encoding' => 'gzip',
            'Content-Type' => 'application/json'
        ];
    }

    /**
     * Estimate compression ratio for given data
     */
    public function estimateCompressionRatio(string $data): float
    {
        $length = strlen($data);
        
        if ($length === 0) {
            return 1.0;
        }
        
        // Count unique characters for basic entropy estimation
        $uniqueChars = count(array_unique(str_split($data)));
        $totalChars = $length;
        
        // More repetitive text compresses better
        $repetitionFactor = 1 - ($uniqueChars / $totalChars);
        
        // JSON/text typically compresses to 20-40% of original size
        $baseRatio = 0.3;
        $adjustedRatio = $baseRatio * (1 - $repetitionFactor * 0.5);
        
        return min(1.0, max(0.1, $adjustedRatio));
    }

    /**
     * Check if compression is beneficial for given data
     */
    public function shouldCompress(string $data): array
    {
        $size = strlen($data);
        $enabled = $this->config['enabled'];
        $aboveThreshold = $size >= $this->config['threshold'];
        $gzipAvailable = function_exists('gzencode');
        $estimatedRatio = $this->estimateCompressionRatio($data);
        $worthCompressing = $estimatedRatio < 0.8; // Only if we expect 20%+ reduction
        
        return [
            'should' => $enabled && $aboveThreshold && $gzipAvailable && $worthCompressing,
            'reasons' => [
                'enabled' => $enabled,
                'aboveThreshold' => $aboveThreshold,
                'gzipAvailable' => $gzipAvailable,
                'worthCompressing' => $worthCompressing,
                'size' => $size,
                'threshold' => $this->config['threshold'],
                'estimatedRatio' => $estimatedRatio
            ]
        ];
    }

    /**
     * Compress JSON data specifically
     */
    public function compressJson(array $data): array
    {
        try {
            $jsonString = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($jsonString === false) {
                return [
                    'data' => $data,
                    'compressed' => false,
                    'error' => 'JSON encoding failed'
                ];
            }
            
            $result = $this->compress($jsonString);
            
            // If compressed, return the compressed string
            // If not compressed, return the original array
            if ($result['compressed']) {
                return $result;
            } else {
                $result['data'] = $data;
                return $result;
            }
        } catch (\Exception $e) {
            $this->logger->error('JSON compression failed', [
                'error' => $e->getMessage(),
                'dataType' => gettype($data)
            ]);

            return [
                'data' => $data,
                'compressed' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Batch compress multiple data items
     */
    public function compressBatch(array $items): array
    {
        $results = [];
        $totalOriginalSize = 0;
        $totalCompressedSize = 0;
        $compressedCount = 0;
        
        foreach ($items as $key => $item) {
            try {
                if (is_array($item)) {
                    $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                }
                
                if (!is_string($item)) {
                    $item = (string) $item;
                }
                
                $result = $this->compress($item);
                $results[$key] = $result;
                
                $totalOriginalSize += $result['originalSize'];
                $totalCompressedSize += $result['compressedSize'];
                
                if ($result['compressed']) {
                    $compressedCount++;
                }
            } catch (\Exception $e) {
                $this->logger->error('Batch compression item failed', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);

                $results[$key] = [
                    'data' => $item,
                    'compressed' => false,
                    'error' => $e->getMessage(),
                    'originalSize' => is_string($item) ? strlen($item) : 0,
                    'compressedSize' => is_string($item) ? strlen($item) : 0,
                    'compressionRatio' => 1.0
                ];
            }
        }
        
        return [
            'results' => $results,
            'summary' => [
                'totalItems' => count($items),
                'compressedItems' => $compressedCount,
                'totalOriginalSize' => $totalOriginalSize,
                'totalCompressedSize' => $totalCompressedSize,
                'overallCompressionRatio' => $totalOriginalSize > 0 ? $totalCompressedSize / $totalOriginalSize : 1.0,
                'spacesSaved' => $totalOriginalSize - $totalCompressedSize
            ]
        ];
    }

    /**
     * Get compression statistics
     */
    public function getStats(string $data): array
    {
        $size = strlen($data);
        $shouldCompress = $this->shouldCompress($data);
        $estimated = $this->estimateCompressionRatio($data);
        
        return [
            'size' => $size,
            'sizeFormatted' => $this->formatBytes($size),
            'shouldCompress' => $shouldCompress['should'],
            'estimatedCompressionRatio' => $estimated,
            'estimatedCompressedSize' => (int) ($size * $estimated),
            'estimatedSavings' => (int) ($size * (1 - $estimated)),
            'config' => $this->config
        ];
    }

    /**
     * Format bytes for human readable display
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = (float) $bytes;
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $updates): void
    {
        $this->config = array_merge($this->config, $updates);
        
        // Validate compression level
        if (isset($this->config['level'])) {
            $this->config['level'] = max(1, min(9, (int) $this->config['level']));
        }
        
        // Validate threshold
        if (isset($this->config['threshold'])) {
            $this->config['threshold'] = max(0, (int) $this->config['threshold']);
        }

        $this->logger->info('Compression service configuration updated', ['config' => $this->config]);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Test compression functionality
     */
    public function test(): array
    {
        try {
            $testData = str_repeat('This is a test string for compression. ', 50);
            
            $result = $this->compress($testData);
            
            if ($result['compressed']) {
                // Test decompression
                $decompressed = $this->decompress($result['data'], $result['encoding'] ?? 'gzip+base64');
                $roundTripSuccess = $decompressed['success'] && $decompressed['data'] === $testData;
            } else {
                $roundTripSuccess = true; // No compression, so data is unchanged
                $decompressed = ['success' => true];
            }
            
            return [
                'compression' => $result,
                'decompression' => $decompressed,
                'roundTripSuccess' => $roundTripSuccess,
                'gzipAvailable' => function_exists('gzencode'),
                'testDataSize' => strlen($testData),
                'overallSuccess' => $roundTripSuccess && !isset($result['error'])
            ];
        } catch (\Exception $e) {
            $this->logger->error('Compression test failed', ['error' => $e->getMessage()]);

            return [
                'compression' => ['compressed' => false, 'error' => $e->getMessage()],
                'decompression' => ['success' => false, 'error' => $e->getMessage()],
                'roundTripSuccess' => false,
                'gzipAvailable' => function_exists('gzencode'),
                'testDataSize' => 0,
                'overallSuccess' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}