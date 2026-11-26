<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractMigration;

final class Version20261126000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create MONEI connection, connect state and callback attempt tables';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $connection = $schema->createTable('monei_connection');
        $connection->addColumn('id', 'integer');
        foreach (['live', 'test'] as $mode) {
            $connection->addColumn($mode.'_account_id', 'string', ['length' => 36, 'notnull' => false]);
            $connection->addColumn($mode.'_api_key', 'text', ['notnull' => false]);
            $connection->addColumn($mode.'_signing_secret', 'text', ['notnull' => false]);
        }
        $connection->addColumn('generation', 'integer');
        $connection->addColumn('last_result', 'json', ['notnull' => false]);
        $connection->setPrimaryKey(['id']);

        $state = $schema->createTable('monei_connect_state');
        $state->addColumn('state', 'string', ['length' => 43]);
        $state->addColumn('verifier', 'text');
        $state->addColumn('redirect_uri', 'string', ['length' => 512]);
        $state->addColumn('generation', 'integer');
        $state->addColumn('created_at', 'datetime_immutable');
        $state->setPrimaryKey(['state']);

        $attempt = $schema->createTable('monei_callback_attempt');
        $attempt->addColumn('id', 'integer', ['autoincrement' => true]);
        $attempt->addColumn('payment_id', 'integer');
        $attempt->addColumn('created_at', 'datetime_immutable');
        $attempt->setPrimaryKey(['id']);
        $attempt->addIndex(['created_at'], 'monei_callback_attempt_created_at_idx');
        $attempt->addIndex(['payment_id', 'created_at'], 'monei_callback_attempt_payment_created_at_idx');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('monei_callback_attempt');
        $schema->dropTable('monei_connect_state');
        $schema->dropTable('monei_connection');
    }
}
