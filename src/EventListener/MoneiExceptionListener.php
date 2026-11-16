<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\EventListener;

use Monei\SyliusPlugin\Callback\InvalidCallbackException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/** Answers a refused or ignored MONEI callback with a plain status, without an error page. */
final readonly class MoneiExceptionListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = self::find($event->getThrowable());
        if (null === $exception) {
            return;
        }

        $level = Response::HTTP_NO_CONTENT === $exception->httpStatus ? 'warning' : 'error';
        $this->logger->log($level, 'MONEI callback not processed: {reason}', [
            'reason' => $exception->getMessage(),
            'status' => $exception->httpStatus,
            'uri' => $event->getRequest()->getRequestUri(),
        ]);

        // Without this, HttpKernel turns the 204 of an ignored callback into a 500.
        $event->allowCustomResponseCode();
        $event->setResponse(new Response('', $exception->httpStatus));
    }

    /** The notify handler's exception arrives wrapped by the command bus. */
    private static function find(?\Throwable $throwable): ?InvalidCallbackException
    {
        for (; null !== $throwable; $throwable = $throwable->getPrevious()) {
            if ($throwable instanceof InvalidCallbackException) {
                return $throwable;
            }
        }

        return null;
    }
}
