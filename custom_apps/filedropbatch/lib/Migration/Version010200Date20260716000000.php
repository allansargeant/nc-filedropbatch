<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the fdb_batches table used to schedule the post-expiry rclone
 * sync of a batch's base folder to the configured site-server.
 */
class Version010200Date20260716000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('fdb_batches')) {
            $table = $schema->createTable('fdb_batches');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('base_folder', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('expiry_date', 'date', [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('synced_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['expiry_date', 'synced_at'], 'fdb_batches_due_idx');
        }

        return $schema;
    }
}
