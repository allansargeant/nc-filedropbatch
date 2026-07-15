<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getBaseFolder()
 * @method void setBaseFolder(string $baseFolder)
 * @method \DateTime getExpiryDate()
 * @method void setExpiryDate(\DateTime $expiryDate)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getSyncedAt()
 * @method void setSyncedAt(?\DateTime $syncedAt)
 */
class Batch extends Entity {
    protected $userId;
    protected $baseFolder;
    protected $expiryDate;
    protected $createdAt;
    protected $syncedAt;

    public function __construct() {
        $this->addType('expiryDate', 'datetime');
        $this->addType('createdAt', 'datetime');
        $this->addType('syncedAt', 'datetime');
    }
}
