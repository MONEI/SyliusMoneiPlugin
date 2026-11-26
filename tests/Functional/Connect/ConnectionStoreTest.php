<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Connect;

use Monei\SyliusPlugin\Connect\ConnectionStore;
use Monei\SyliusPlugin\Entity\MoneiConnection;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Sylius\Component\Payment\Encryption\EncrypterInterface;

final class ConnectionStoreTest extends MoneiTestCase
{
    private const KEY = 'mk_test_fixture';
    private const SECRET = 'mksec_fixture';

    public function testCredentialsAndStateVerifierAreEncryptedAtRest(): void
    {
        $store = $this->store();
        $state = str_repeat('a', 43);
        $verifier = str_repeat('v', 43);
        $store->writeMode(false, self::ACCOUNT_ID, self::KEY, self::SECRET);
        $store->insertState($state, $verifier, 'https://shop.example/monei/connect', 0, new \DateTimeImmutable());

        $db = $this->entityManager()->getConnection();
        $storedKey = $db->fetchOne('SELECT test_api_key FROM monei_connection WHERE id = 1');
        $storedSecret = $db->fetchOne('SELECT test_signing_secret FROM monei_connection WHERE id = 1');
        $storedVerifier = $db->fetchOne('SELECT verifier FROM monei_connect_state WHERE state = ?', [$state]);
        foreach ([[$storedKey, self::KEY], [$storedSecret, self::SECRET], [$storedVerifier, $verifier]] as [$value, $plaintext]) {
            self::assertTrue(\is_string($value) && str_ends_with($value, EncrypterInterface::ENCRYPTION_SUFFIX) && !str_contains($value, $plaintext));
        }
        self::assertTrue(self::KEY === $store->read()->testApiKey);
        self::assertTrue(self::SECRET === $store->read()->testSigningSecret);
        self::assertTrue($verifier === $store->readState($state)?->verifier);

        $withoutEncryption = new ConnectionStore($db, null);
        self::assertNull($withoutEncryption->read()->testApiKey);
        self::assertSame(0, $store->deleteState(strtoupper($state)));
        self::assertSame(1, $store->deleteState($state));
        self::assertSame(0, $store->deleteState($state));
    }

    public function testFlushingAnOrmSnapshotCannotRestoreDisconnectedCredentials(): void
    {
        $store = $this->store();
        $store->writeMode(false, self::ACCOUNT_ID, self::KEY, self::SECRET);
        $manager = $this->entityManager();
        $snapshot = $manager->find(MoneiConnection::class, 1);
        self::assertInstanceOf(MoneiConnection::class, $snapshot);
        self::assertNotNull($snapshot->testApiKey);

        (new ConnectionStore($this->secondConnection(), null))->clear();
        $manager->flush();

        $connection = $store->read();
        self::assertNull($connection->testApiKey);
        self::assertNull($connection->testSigningSecret);
        self::assertSame(1, $connection->generation);
    }

    public function testWritingAfterAPurgeCreatesTheSingletonAndIdenticalUpdatesSucceed(): void
    {
        $db = $this->entityManager()->getConnection();
        self::assertFalse($db->fetchOne('SELECT id FROM monei_connection'));
        // With plaintext, an identical write reports zero changed rows on MySQL.
        $store = new ConnectionStore($db, null);
        $store->writeMode(false, self::ACCOUNT_ID, self::KEY, self::SECRET);
        $store->writeMode(false, self::ACCOUNT_ID, self::KEY, self::SECRET);
        $store->writeResult(['test' => 'connected']);
        $store->writeResult(['test' => 'connected']);

        $connection = $store->read();
        self::assertTrue(self::KEY === $connection->testApiKey);
        self::assertSame(['test' => 'connected'], $connection->lastResult);
    }

    private function store(): ConnectionStore
    {
        $store = self::getContainer()->get('monei.test.connection_store');
        \assert($store instanceof ConnectionStore);

        return $store;
    }
}
