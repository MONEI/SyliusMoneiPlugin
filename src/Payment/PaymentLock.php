<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * One mutex per Sylius payment, shared by the return page, the MONEI callback and the
 * admin capture, cancel and refund: a database row lock (SELECT ... FOR UPDATE) held
 * until the request's changes are committed. Without it, two requests can apply the
 * same transition and the order payment listeners (inventory) run twice.
 *
 * The payment request bus already runs its handlers in a transaction. An admin
 * transition runs outside one, so this class opens it and commits it when the main
 * request ends, after the controller's flush.
 */
final class PaymentLock implements EventSubscriberInterface
{
    private bool $ownsTransaction = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Locks the payment row and reloads it, so the caller sees the state of the last
     * committed request. Returns false when no lock can be taken: no transaction and no
     * main request to end one (a console command).
     */
    public function acquire(PaymentInterface $payment): bool
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            if (null === $this->requestStack->getMainRequest()) {
                return false;
            }
            $connection->beginTransaction();
            $this->ownsTransaction = true;
        }

        $this->entityManager->lock($payment, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($payment);

        return true;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Late: the controller has flushed by then.
            KernelEvents::RESPONSE => ['onResponse', -1024],
            KernelEvents::EXCEPTION => ['onException', 1024],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->ownsTransaction) {
            return;
        }
        $this->ownsTransaction = false;

        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->commit();
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->ownsTransaction) {
            return;
        }
        $this->ownsTransaction = false;

        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }
}
