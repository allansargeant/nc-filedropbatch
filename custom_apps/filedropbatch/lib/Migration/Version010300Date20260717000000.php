<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the fdb_sessions table so individual sessions (from a CSV batch or
 * created manually) persist as editable/closeable/deletable records, rather
 * than existing only transiently during CSV processing.
 */
class Version010300Date20260717000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('fdb_sessions')) {
            $table = $schema->createTable('fdb_sessions');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('batch_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('theatre', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('date', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('start_time', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('presenter_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('presenter_email', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('base_folder', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('folder_path', 'string', [
                'notnull' => true,
                'length' => 1024,
            ]);
            // Provider-prefixed full share id (IShare::getFullId(), e.g. "ocinternal:42"),
            // the form IManager::getShareById() expects - not the bare numeric id.
            $table->addColumn('share_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 16,
                'default' => 'open',
            ]);
            // Nullable rather than notnull: Nextcloud's own migration validation
            // rejects a NOT NULL boolean column whose default resolves to false
            // (a known MySQL/MariaDB-related restriction) - and an *unset*
            // default is apparently also treated as "false" by that check, so
            // even omitting 'default' entirely still trips it while 'notnull'
            // is true. Confirmed live against this exact schema. Application
            // code always sets this explicitly on insert regardless.
            $table->addColumn('email_sent', 'boolean', [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('closed_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'fdb_sessions_user_idx');
            $table->addIndex(['batch_id'], 'fdb_sessions_batch_idx');
        }

        return $schema;
    }
}
