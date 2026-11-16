<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\TestApplication\src;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Test env only: just before PaymentTransitionListener runs, commits a new payment state
 * from another connection, as a concurrent request would. Off unless a test switches it
 * on; it fires once. State is static: KernelBrowser reboots the kernel between requests.
 */
final class CommitPaymentStateListener
{
    private static ?string $state = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function commitOnce(string $state): void
    {
        self::$state = $state;
    }

    public static function reset(): void
    {
        self::$state = null;
    }

    public function __invoke(TransitionEvent $event): void
    {
        $payment = $event->getSubject();
        if (null === self::$state || !$payment instanceof PaymentInterface) {
            return;
        }

        $other = DriverManager::getConnection($this->connection->getParams());
        try {
            $other->executeStatement('UPDATE sylius_payment SET state = ? WHERE id = ?', [self::$state, $payment->getId()]);
        } finally {
            $other->close();
            self::$state = null;
        }
    }
}
