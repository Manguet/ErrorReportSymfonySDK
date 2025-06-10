<?php

namespace ErrorExplorer\ErrorReporter\EventListener;

use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Response;

class ErrorReportingListener implements EventSubscriberInterface
{
    /** @var WebhookErrorReporter */
    private $errorReporter;
    /** @var string */
    private $environment;

    public function __construct(WebhookErrorReporter $errorReporter, $environment)
    {
        $this->errorReporter = $errorReporter;
        $this->environment = $environment;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();
        
        $httpStatus = $this->determineHttpStatus($exception);
        
        $this->errorReporter->reportError($exception, $this->environment, $httpStatus, $request);
    }

    private function determineHttpStatus(\Throwable $exception)
    {
        if (method_exists($exception, 'getStatusCode')) {
            return $exception->getStatusCode();
        }

        $exceptionClass = get_class($exception);
        
        // Use strpos instead of str_contains for PHP 7.2 compatibility
        if (strpos($exceptionClass, 'NotFound') !== false) {
            return Response::HTTP_NOT_FOUND;
        }
        if (strpos($exceptionClass, 'AccessDenied') !== false) {
            return Response::HTTP_FORBIDDEN;
        }
        if (strpos($exceptionClass, 'Unauthorized') !== false) {
            return Response::HTTP_UNAUTHORIZED;
        }
        if (strpos($exceptionClass, 'BadRequest') !== false) {
            return Response::HTTP_BAD_REQUEST;
        }
        if (strpos($exceptionClass, 'MethodNotAllowed') !== false) {
            return Response::HTTP_METHOD_NOT_ALLOWED;
        }
        
        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }
}
