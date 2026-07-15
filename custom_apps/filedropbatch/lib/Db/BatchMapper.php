<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Batch>
 */
class BatchMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'fdb_batches', Batch::class);
    }

    public function insertBatch(string $userId, string $baseFolder, \DateTimeInterface $expiryDate): Batch {
        $batch = new Batch();
        $batch->setUserId($userId);
        $batch->setBaseFolder($baseFolder);
        $batch->setExpiryDate(\DateTime::createFromInterface($expiryDate));
        $batch->setCreatedAt(new \DateTime());
        $batch->setSyncedAt(null);

        return $this->insert($batch);
    }

    /**
     * Batches whose expiry has passed and that haven't been synced yet.
     *
     * @return Batch[]
     */
    public function findDue(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->lte('expiry_date', $qb->createNamedParameter(new \DateTime('today'), IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->isNull('synced_at'));

        return $this->findEntities($qb);
    }

    /** @param int[] $ids */
    public function markSynced(array $ids): void {
        if ($ids === []) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('synced_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $qb->executeStatement();
    }
}
