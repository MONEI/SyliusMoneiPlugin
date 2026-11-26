<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Monei\SyliusPlugin\Callback\InvalidCallbackException;
use Monei\SyliusPlugin\Exception\MoneiRefusedException;
use Monei\SyliusPlugin\Http\ApiRequest;
use Monei\SyliusPlugin\Http\Flash;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Answers a refused or ignored MONEI callback with a plain status, without an error page.
 * Turns a MONEI refusal of an admin action into an error flash on the order page (HTML)
 * or a 409 (API), after it rolls back the transaction Core's ResourceUpdateHandler leaves open.
 */
final readonly class MoneiExceptionListener
{
    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $refusal = self::find($event->getThrowable(), MoneiRefusedException::class);
        if (null !== $refusal) {
            $this->refused($event, $refusal);

            return;
        }

        $exception = self::find($event->getThrowable(), InvalidCallbackException::class);
        if (null === $exception) {
            return;
        }

        $level = $exception->httpStatus < 400 ? 'warning' : 'error';
        $this->logger->log($level, 'MONEI callback not processed: {reason}', [
            'reason' => $exception->getMessage(),
            'status' => $exception->httpStatus,
            'uri' => $event->getRequest()->getRequestUri(),
        ]);

        // Without this, HttpKernel turns the 204 of an ignored callback into a 500.
        $event->allowCustomResponseCode();
        $event->setResponse(new Response('', $exception->httpStatus));
    }

    private function refused(ExceptionEvent $event, MoneiRefusedException $refusal): void
    {
        $connection = $this->entityManager->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        // The refused transition's changes (for example an order already `cancelled`) stay in memory otherwise.
        $this->entityManager->clear();
        // An earlier MONEI call of the same action (for example another payment's hold release) is not rolled back.
        $this->logger->error('Action on order {order} refused: {reason}', [
            'order' => $refusal->orderId,
            'reason' => $refusal->getMessage(),
            'parameters' => $refusal->parameters,
            'uri' => $event->getRequest()->getRequestUri(),
        ]);

        $request = $event->getRequest();
        if (ApiRequest::matches($request)) {
            $event->setResponse(new JsonResponse([
                'code' => Response::HTTP_CONFLICT,
                'message' => $this->translator->trans($refusal->getMessage(), $refusal->parameters, 'flashes'),
            ], Response::HTTP_CONFLICT));

            return;
        }

        Flash::add($this->requestStack, 'error', ['message' => $refusal->getMessage(), 'parameters' => $refusal->parameters]);
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('sylius_admin_order_show', ['id' => $refusal->orderId])));
    }

    /**
     * Handler exceptions arrive wrapped by the command bus, and workflow refusals by the state machine.
     *
     * @template T of \Throwable
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private static function find(?\Throwable $throwable, string $class): ?\Throwable
    {
        for (; null !== $throwable; $throwable = $throwable->getPrevious()) {
            if ($throwable instanceof $class) {
                return $throwable;
            }
        }

        return null;
    }
}
