<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getBatchId()
 * @method void setBatchId(int $batchId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTheatre()
 * @method void setTheatre(string $theatre)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string getStartTime()
 * @method void setStartTime(string $startTime)
 * @method string getPresenterName()
 * @method void setPresenterName(string $presenterName)
 * @method string getPresenterEmail()
 * @method void setPresenterEmail(string $presenterEmail)
 * @method string getBaseFolder()
 * @method void setBaseFolder(string $baseFolder)
 * @method string getFolderPath()
 * @method void setFolderPath(string $folderPath)
 * @method string getShareId()
 * @method void setShareId(string $shareId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method bool getEmailSent()
 * @method void setEmailSent(bool $emailSent)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getClosedAt()
 * @method void setClosedAt(?\DateTime $closedAt)
 */
class Session extends Entity {
    protected $batchId;
    protected $userId;
    protected $theatre;
    protected $date;
    protected $startTime;
    protected $presenterName;
    protected $presenterEmail;
    protected $baseFolder;
    protected $folderPath;
    protected $shareId;
    protected $status;
    protected $emailSent;
    protected $createdAt;
    protected $closedAt;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public function __construct() {
        $this->addType('batchId', 'integer');
        $this->addType('emailSent', 'boolean');
        $this->addType('createdAt', 'datetime');
        $this->addType('closedAt', 'datetime');
    }
}
