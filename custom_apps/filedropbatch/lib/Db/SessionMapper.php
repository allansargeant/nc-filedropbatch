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
}
