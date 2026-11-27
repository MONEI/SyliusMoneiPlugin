<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional\Connect;

use Monei\SyliusPlugin\Connect\ConnectionStore;
use Monei\SyliusPlugin\Tests\Functional\MoneiTestCase;
use Sylius\Component\Payment\Encryption\EncrypterInterface;

final class WithoutEncryptionContainerTest extends MoneiTestCase
{
    public function testStoreCompilesWithoutTheEncrypterAndRoundTripsPlaintext(): void
    {
        if ('1' !== getenv('MONEI_TEST_WITHOUT_ENCRYPTION')) {
            self::markTestSkipped('Run with the test kernel configured without payment encryption.');
        }

        $container = self::getContainer();
        self::assertFalse($container->has('sylius.encrypter'));
        $store = $container->get('monei.test.connection_store');
        self::assertInstanceOf(ConnectionStore::class, $store);
        $key = 'mk_test_fixture';
        $secret = 'mksec_fixture';
        $store->writeMode(false, self::ACCOUNT_ID, $key, $secret);

        $db = $this->entityManager()->getConnection();
        self::assertTrue($key === $db->fetchOne('SELECT test_api_key FROM monei_connection WHERE id = 1'));
        self::assertTrue($secret === $store->read()->testSigningSecret);
    }

    public function testDisablingEncryptionDoesNotExposePreviouslyEncryptedCredentialsAsUsable(): void
    {
        $this->createConnectedTestMethod();
        $db = $this->entityManager()->getConnection();
        $db->update('monei_connection', [
            'test_api_key' => 'mk_test_fixture'.EncrypterInterface::ENCRYPTION_SUFFIX,
            'test_signing_secret' => 'mksec_fixture'.EncrypterInterface::ENCRYPTION_SUFFIX,
        ], ['id' => 1]);

        $store = self::getContainer()->get('monei.test.connection_store');
        self::assertInstanceOf(ConnectionStore::class, $store);
        $snapshot = $store->read();

        self::assertSame(self::ACCOUNT_ID, $snapshot->testAccountId);
        self::assertNull($snapshot->testApiKey);
        self::assertNull($snapshot->testSigningSecret);
    }
}
