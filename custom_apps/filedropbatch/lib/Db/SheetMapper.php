<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Sheet>
 */
class SheetMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'fdb_sheets', Sheet::class);
    }

    /** @return Sheet[] newest first */
    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * @throws DoesNotExistException if no sheet with this id is owned by $userId
     */
    public function findOwned(int $id, string $userId): Sheet {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /** @return Sheet[] */
    public function findAllSyncEnabled(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('sync_enabled', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));

        return $this->findEntities($qb);
    }
}
