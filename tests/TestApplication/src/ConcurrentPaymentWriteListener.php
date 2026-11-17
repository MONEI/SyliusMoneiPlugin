<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\TestApplication\src;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Test env only: just before PaymentTransitionListener runs, commits a new payment state or
 * method from another connection, as a concurrent request would. Off unless a test switches
 * it on; it fires once. State is static: KernelBrowser reboots the kernel between requests.
 */
final class ConcurrentPaymentWriteListener
{
    private static ?string $state = null;

    private static ?int $methodId = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function commitOnce(string $state): void
    {
        self::$state = $state;
    }

    public static function commitMethodOnce(int $methodId): void
    {
        self::$methodId = $methodId;
    }

    public static function reset(): void
    {
        self::$state = null;
        self::$methodId = null;
    }

    public function __invoke(TransitionEvent $event): void
    {
        $payment = $event->getSubject();
        if ((null === self::$state && null === self::$methodId) || !$payment instanceof PaymentInterface) {
            return;
        }

        $other = DriverManager::getConnection($this->connection->getParams());
        try {
            if (null !== self::$state) {
                $other->executeStatement('UPDATE sylius_payment SET state = ? WHERE id = ?', [self::$state, $payment->getId()]);
            }
            if (null !== self::$methodId) {
                $other->executeStatement('UPDATE sylius_payment SET method_id = ? WHERE id = ?', [self::$methodId, $payment->getId()]);
            }
        } finally {
            $other->close();
            self::reset();
        }
    }
}
