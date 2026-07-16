<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Session>
 */
class SessionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'fdb_sessions', Session::class);
    }

    /** @return Session[] newest first */
    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * @throws DoesNotExistException if no session with this id is owned by $userId
     */
    public function findOwned(int $id, string $userId): Session {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /** Open sessions created/last-touched by the given linked sheet. */
    public function findOpenBySheet(int $sheetId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Session::STATUS_OPEN)));

        return $this->findEntities($qb);
    }

    /**
     * Unlinking a sheet only forgets which sheet created these sessions -
     * the sessions themselves, their folders, and their shares are untouched.
     */
    public function clearSheetId(int $sheetId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('sheet_id', $qb->createNamedParameter(null))
            ->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId, \PDO::PARAM_INT)));
        $qb->executeStatement();
    }
}
