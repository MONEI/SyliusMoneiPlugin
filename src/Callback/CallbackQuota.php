<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Callback;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\RetryableException;
use Monei\SyliusPlugin\Connect\ConnectionStore;
use Psr\Clock\ClockInterface;

final readonly class CallbackQuota
{
    private const WINDOW = '-60 seconds';
    private const PAYMENT_LIMIT = 5;
    private const INSTALL_LIMIT = 60;

    public function __construct(
        private Connection $defaultConnection,
        private ConnectionStore $store,
        private ClockInterface $clock,
    ) {
    }

    public function check(int $paymentId, string $moneiPaymentId): void
    {
        $this->checkLimits($this->store, $this->clock->now()->modify(self::WINDOW), $paymentId, $moneiPaymentId);
    }

    public function charge(int $paymentId, string $moneiPaymentId): void
    {
        // Commit independently of Messenger, before the fetch, even if the fetch later fails.
        $connection = DriverManager::getConnection($this->defaultConnection->getParams());
        $store = new ConnectionStore($connection, null);

        try {
            $store->ensure();
            $connection->transactional(function () use ($store, $paymentId, $moneiPaymentId): void {
                $store->lockRow();
                $now = $this->clock->now();
                $cutoff = $now->modify(self::WINDOW);
                $store->deleteExpiredAttempts($cutoff);
                $this->checkLimits($store, $cutoff, $paymentId, $moneiPaymentId);
                $store->insertAttempt($paymentId, $now);
            });
        } catch (RetryableException) {
            throw InvalidCallbackException::unavailable($moneiPaymentId, 'the callback quota is temporarily locked');
        } finally {
            $connection->close();
        }
    }

    private function checkLimits(ConnectionStore $store, \DateTimeImmutable $cutoff, int $paymentId, string $moneiPaymentId): void
    {
        if ($store->countAttempts($cutoff, $paymentId) >= self::PAYMENT_LIMIT || $store->countAttempts($cutoff) >= self::INSTALL_LIMIT) {
            throw InvalidCallbackException::unavailable($moneiPaymentId, 'the unsigned callback quota is exhausted');
        }
    }
}
