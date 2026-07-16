<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getSheetUrl()
 * @method void setSheetUrl(string $sheetUrl)
 * @method string getSpreadsheetId()
 * @method void setSpreadsheetId(string $spreadsheetId)
 * @method string getBaseFolder()
 * @method void setBaseFolder(string $baseFolder)
 * @method string getExpiryDate()
 * @method void setExpiryDate(string $expiryDate)
 * @method string getRootFolderNames()
 * @method void setRootFolderNames(string $rootFolderNames)
 * @method bool getCreateUsers()
 * @method void setCreateUsers(bool $createUsers)
 * @method bool getSyncEnabled()
 * @method void setSyncEnabled(bool $syncEnabled)
 * @method \DateTime|null getLastSyncedAt()
 * @method void setLastSyncedAt(?\DateTime $lastSyncedAt)
 * @method string getLastSyncStatus()
 * @method void setLastSyncStatus(string $lastSyncStatus)
 * @method string getLastSyncMessage()
 * @method void setLastSyncMessage(string $lastSyncMessage)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Sheet extends Entity {
    protected $userId;
    protected $name;
    protected $sheetUrl;
    protected $spreadsheetId;
    protected $baseFolder;
    protected $expiryDate;
    protected $rootFolderNames;
    protected $createUsers;
    protected $syncEnabled;
    protected $lastSyncedAt;
    protected $lastSyncStatus;
    protected $lastSyncMessage;
    protected $createdAt;

    public function __construct() {
        $this->addType('createUsers', 'boolean');
        $this->addType('syncEnabled', 'boolean');
        $this->addType('lastSyncedAt', 'datetime');
        $this->addType('createdAt', 'datetime');
    }
}
