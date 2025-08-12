<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\ValueObject;

use Throwable;

class ErrorFingerprint
{
    public function __construct(
        public string $value,
        public string $exceptionClass,
        public string $file,
        public int $line,
    ) {}

    public static function fromException(Throwable $exception): self
    {
        // Enhanced fingerprint combining stack trace signature + message
        $stackSignature = self::extractStackSignature($exception->getTraceAsString(), 3);
        $messageSignature = substr($exception->getMessage(), 0, 100);
        
        // Combine both signatures
        $fingerprint = sprintf(
            '%s|%s|%s',
            $stackSignature,
            $messageSignature,
            $exception::class
        );

        return new self(
            value: hash('sha256', $fingerprint),
            exceptionClass: $exception::class,
            file: $exception->getFile(),
            line: $exception->getLine(),
        );
    }

    public static function fromMessage(string $message, string $level = 'error'): self
    {
        $fingerprint = sprintf(
            'CustomMessage:%s:%s',
            $level,
            md5($message)
        );

        return new self(
            value: md5($fingerprint),
            exceptionClass: 'CustomMessage',
            file: 'N/A',
            line: 0,
        );
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Extract stack trace signature by taking the first N meaningful frames
     * and normalizing line numbers to avoid over-segmentation
     */
    private static function extractStackSignature(string $stackTrace, int $depth = 3): string
    {
        $lines = explode("\n", $stackTrace);
        
        // Filter and take meaningful frames (skip empty lines and first line)
        $meaningfulFrames = array_filter($lines, function($line) {
            return !empty(trim($line)) && strpos($line, '#') === 0;
        });
        
        // Take first N frames
        $frames = array_slice($meaningfulFrames, 0, $depth);
        
        // Normalize each frame (remove specific line numbers)
        $normalizedFrames = array_map(function($frame) {
            // Replace line numbers with XX to avoid over-segmentation
            return preg_replace('/:\d+/', ':XX', $frame);
        }, $frames);
        
        return implode('|', $normalizedFrames);
    }
}
