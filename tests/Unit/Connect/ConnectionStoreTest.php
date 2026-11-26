<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Connect;

use Doctrine\DBAL\Connection;
use Monei\SyliusPlugin\Connect\ConnectionStore;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Encryption\Encrypter;

final class ConnectionStoreTest extends TestCase
{
    public function testExistingPlaintextCredentialsRemainReadableAfterEnablingEncryption(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(1);
        $row = [
            'live_account_id' => null,
            'live_api_key' => null,
            'live_signing_secret' => null,
            'test_account_id' => 'fixture-account',
            'test_api_key' => 'mk_test_fixture',
            'test_signing_secret' => 'mksec_fixture',
            'generation' => 0,
            'last_result' => null,
        ];
        $db->method('fetchAssociative')->willReturn($row);

        // Sylius should pass through legacy plaintext without loading a key.
        $store = new ConnectionStore($db, new Encrypter('unused'));
        $connection = $store->read();

        self::assertTrue($row['test_api_key'] === $connection->testApiKey);
        self::assertTrue($row['test_signing_secret'] === $connection->testSigningSecret);
    }
}
