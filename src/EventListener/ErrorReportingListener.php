<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\EventListener;

use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

#[AsEventListener(event: KernelEvents::EXCEPTION, method: 'onKernelException', priority: 0)]
final class ErrorReportingListener
{
    public function __construct(
        private readonly WebhookErrorReporter $errorReporter,
        private readonly string $environment,
    ) {}

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();
        $httpStatus = $this->determineHttpStatus($exception);

        $this->errorReporter->reportError($exception, $this->environment, $httpStatus, $request);
    }

    private function determineHttpStatus(Throwable $exception): int
    {
        if (method_exists($exception, 'getStatusCode')) {
            return $exception->getStatusCode();
        }

        $exceptionClass = $exception::class;

        return match (true) {
            str_contains($exceptionClass, 'NotFound') => Response::HTTP_NOT_FOUND,
            str_contains($exceptionClass, 'AccessDenied') => Response::HTTP_FORBIDDEN,
            str_contains($exceptionClass, 'Unauthorized') => Response::HTTP_UNAUTHORIZED,
            str_contains($exceptionClass, 'BadRequest') => Response::HTTP_BAD_REQUEST,
            str_contains($exceptionClass, 'MethodNotAllowed') => Response::HTTP_METHOD_NOT_ALLOWED,
            str_contains($exceptionClass, 'TooManyRequests') => Response::HTTP_TOO_MANY_REQUESTS,
            str_contains($exceptionClass, 'Conflict') => Response::HTTP_CONFLICT,
            str_contains($exceptionClass, 'UnprocessableEntity') => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }
}
