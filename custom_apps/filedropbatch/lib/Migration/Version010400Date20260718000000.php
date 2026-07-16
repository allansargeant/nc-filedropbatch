<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the fdb_sheets table (one row per linked Google Sheet) and adds a
 * nullable sheet_id column to fdb_sessions linking a session back to the
 * sheet that created/last-touched it - null for CSV/manual sessions.
 */
class Version010400Date20260718000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('fdb_sheets')) {
            $table = $schema->createTable('fdb_sheets');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('sheet_url', 'string', [
                'notnull' => true,
                'length' => 1024,
            ]);
            $table->addColumn('spreadsheet_id', 'string', [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('base_folder', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('expiry_date', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('root_folder_names', 'text', [
                'notnull' => false,
            ]);
            // See fdb_sessions.email_sent for why boolean columns here are
            // nullable rather than notnull-with-default - Nextcloud's own
            // migration validation rejects a NOT NULL boolean whose default
            // resolves to false. Application code always sets these explicitly.
            $table->addColumn('create_users', 'boolean', [
                'notnull' => false,
            ]);
            $table->addColumn('sync_enabled', 'boolean', [
                'notnull' => false,
            ]);
            $table->addColumn('last_synced_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('last_sync_status', 'string', [
                'notnull' => false,
                'length' => 16,
            ]);
            $table->addColumn('last_sync_message', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'fdb_sheets_user_idx');
            $table->addIndex(['sync_enabled'], 'fdb_sheets_sync_idx');
        }

        if ($schema->hasTable('fdb_sessions')) {
            $sessions = $schema->getTable('fdb_sessions');
            if (!$sessions->hasColumn('sheet_id')) {
                $sessions->addColumn('sheet_id', 'bigint', [
                    'notnull' => false,
                ]);
                $sessions->addIndex(['sheet_id'], 'fdb_sessions_sheet_idx');
            }
        }

        return $schema;
    }
}
