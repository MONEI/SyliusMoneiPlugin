<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Connect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Sylius\Component\Payment\Encryption\Exception\EncryptionException;

final readonly class ConnectionStore
{
    private const ID = 1;

    public function __construct(
        private Connection $connection,
        private ?EncrypterInterface $encrypter,
    ) {
    }

    public function ensure(): void
    {
        if (false !== $this->connection->fetchOne('SELECT id FROM monei_connection WHERE id = ?', [self::ID])) {
            return;
        }

        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Ensure the MONEI connection before starting a transaction.');
        }

        try {
            $this->connection->insert('monei_connection', ['id' => self::ID, 'generation' => 0]);
        } catch (UniqueConstraintViolationException) {
            // Another request created the singleton after the read.
        }
    }

    public function read(): ConnectionSnapshot
    {
        return $this->readConnection(false);
    }

    public function lock(): ConnectionSnapshot
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Lock the MONEI connection inside a transaction.');
        }

        return $this->readConnection(true);
    }

    public function lockRow(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Lock the MONEI connection inside a transaction.');
        }

        if (false === $this->connection->fetchOne('SELECT id FROM monei_connection WHERE id = ? FOR UPDATE', [self::ID])) {
            throw new \RuntimeException('The MONEI connection row is missing.');
        }
    }

    public function writeMode(bool $live, string $accountId, string $apiKey, string $signingSecret): void
    {
        $this->ensure();
        $mode = $live ? 'live' : 'test';

        $this->connection->transactional(function () use ($mode, $accountId, $apiKey, $signingSecret): void {
            $this->lock();
            $this->connection->update('monei_connection', [
                $mode.'_account_id' => $accountId,
                $mode.'_api_key' => $this->encrypt($apiKey),
                $mode.'_signing_secret' => $this->encrypt($signingSecret),
            ], ['id' => self::ID]);
        });
    }

    /** @param array<string, mixed> $result */
    public function writeResult(array $result): void
    {
        $this->ensure();
        $this->connection->transactional(function () use ($result): void {
            $this->lock();
            $this->connection->update('monei_connection', ['last_result' => $result], ['id' => self::ID], ['last_result' => Types::JSON]);
        });
    }

    public function clear(): void
    {
        $this->ensure();
        $this->connection->transactional(function (): void {
            $locked = $this->lock();
            $this->connection->update('monei_connection', [
                'live_account_id' => null,
                'live_api_key' => null,
                'live_signing_secret' => null,
                'test_account_id' => null,
                'test_api_key' => null,
                'test_signing_secret' => null,
                'generation' => $locked->generation + 1,
            ], ['id' => self::ID]);
            $this->connection->executeStatement('DELETE FROM monei_connect_state');
        });
    }

    public function insertState(string $state, string $verifier, string $redirectUri, int $generation, \DateTimeImmutable $createdAt): void
    {
        $this->connection->transactional(function () use ($state, $verifier, $redirectUri, $generation, $createdAt): void {
            $this->connection->insert('monei_connect_state', [
                'state' => $state,
                'verifier' => $this->encrypt($verifier),
                'redirect_uri' => $redirectUri,
                'generation' => $generation,
                'created_at' => $createdAt,
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        });
    }

    public function readState(string $state): ?PendingConnectState
    {
        /** @var array{state: string, verifier: string, redirect_uri: string, generation: int|numeric-string, created_at: string}|false $row */
        $row = $this->connection->fetchAssociative('SELECT * FROM monei_connect_state WHERE state = ?', [$state]);
        // The database collation may compare state values case-insensitively.
        if (false === $row || $row['state'] !== $state) {
            return null;
        }

        $verifier = $this->decrypt($row['verifier']);

        return null === $verifier ? null : new PendingConnectState(
            $state,
            $verifier,
            $row['redirect_uri'],
            (int) $row['generation'],
            new \DateTimeImmutable($row['created_at']),
        );
    }

    public function deleteState(string $state): int
    {
        return $this->connection->transactional(function () use ($state): int {
            $stored = $this->connection->fetchOne('SELECT state FROM monei_connect_state WHERE state = ? FOR UPDATE', [$state]);

            return $stored === $state ? (int) $this->connection->delete('monei_connect_state', ['state' => $state]) : 0;
        });
    }

    public function deleteExpiredStates(\DateTimeImmutable $cutoff): void
    {
        $this->deleteBefore('monei_connect_state', $cutoff);
    }

    public function deleteExpiredAttempts(\DateTimeImmutable $cutoff): void
    {
        $this->deleteBefore('monei_callback_attempt', $cutoff);
    }

    public function countAttempts(\DateTimeImmutable $since, ?int $paymentId = null): int
    {
        $query = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('monei_callback_attempt')
            ->where('created_at >= :since')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        if (null !== $paymentId) {
            $query->andWhere('payment_id = :paymentId')->setParameter('paymentId', $paymentId, Types::INTEGER);
        }

        return (int) $query->executeQuery()->fetchOne();
    }

    public function insertAttempt(int $paymentId, \DateTimeImmutable $createdAt): void
    {
        $this->connection->transactional(function () use ($paymentId, $createdAt): void {
            $this->connection->insert('monei_callback_attempt', [
                'payment_id' => $paymentId,
                'created_at' => $createdAt,
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        });
    }

    private function deleteBefore(string $table, \DateTimeImmutable $cutoff): void
    {
        $this->connection->transactional(function () use ($table, $cutoff): void {
            $this->connection->executeStatement('DELETE FROM '.$table.' WHERE created_at < ?', [$cutoff], [Types::DATETIME_IMMUTABLE]);
        });
    }

    private function readConnection(bool $lock): ConnectionSnapshot
    {
        // DBAL always reads the database instead of returning an ORM identity-map snapshot.
        $sql = 'SELECT * FROM monei_connection WHERE id = ?';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        /** @var array{live_account_id: ?string, live_api_key: ?string, live_signing_secret: ?string, test_account_id: ?string, test_api_key: ?string, test_signing_secret: ?string, generation: int|numeric-string, last_result: ?string}|false $row */
        $row = $this->connection->fetchAssociative($sql, [self::ID]);
        if (false === $row) {
            if (!$lock) {
                return new ConnectionSnapshot(null, null, null, null, null, null, 0, null);
            }

            throw new \RuntimeException('The MONEI connection row is missing.');
        }

        [$liveAccount, $liveKey, $liveSecret] = $this->readMode($row['live_account_id'], $row['live_api_key'], $row['live_signing_secret']);
        [$testAccount, $testKey, $testSecret] = $this->readMode($row['test_account_id'], $row['test_api_key'], $row['test_signing_secret']);
        /** @var array<string, mixed>|null $lastResult */
        $lastResult = null === $row['last_result'] ? null : json_decode($row['last_result'], true, flags: \JSON_THROW_ON_ERROR);

        return new ConnectionSnapshot($liveAccount, $liveKey, $liveSecret, $testAccount, $testKey, $testSecret, (int) $row['generation'], $lastResult);
    }

    /** @return array{?string, null, null}|array{string, string, string} */
    private function readMode(?string $accountId, ?string $storedKey, ?string $storedSecret): array
    {
        $key = null === $storedKey ? null : $this->decrypt($storedKey);
        $secret = null === $storedSecret ? null : $this->decrypt($storedSecret);

        if (null === $accountId || '' === $accountId || null === $key || !str_starts_with($key, 'mk_') || null === $secret || !str_starts_with($secret, 'mksec_')) {
            // Preserve the account binding so a later swap can still detect a conflicting Live account.
            return [$accountId, null, null];
        }

        return [$accountId, $key, $secret];
    }

    private function encrypt(string $value): string
    {
        return $this->encrypter?->encrypt($value) ?? $value;
    }

    private function decrypt(string $value): ?string
    {
        if (null === $this->encrypter) {
            return str_ends_with($value, EncrypterInterface::ENCRYPTION_SUFFIX) ? null : $value;
        }

        try {
            return $this->encrypter->decrypt($value);
        } catch (EncryptionException) {
            return null;
        }
    }
}
