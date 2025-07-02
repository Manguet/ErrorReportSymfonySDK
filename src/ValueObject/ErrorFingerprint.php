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
        $fingerprint = sprintf(
            '%s:%s:%d',
            $exception::class,
            $exception->getFile(),
            $exception->getLine()
        );

        return new self(
            value: md5($fingerprint),
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
}
