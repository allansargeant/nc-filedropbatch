<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\BackgroundJob;

use OCA\FileDropBatch\AppInfo\Application;
use OCA\FileDropBatch\Db\SheetMapper;
use OCA\FileDropBatch\Service\SheetSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodically re-syncs every sync-enabled linked Google Sheet. Each sheet
 * is synced in its own try/catch so one broken sheet (revoked access,
 * deleted spreadsheet) doesn't block the rest.
 */
class SyncGoogleSheetsJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private SheetMapper $sheetMapper,
        private SheetSyncService $syncService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(20 * 60);
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }

    protected function run($argument): void {
        foreach ($this->sheetMapper->findAllSyncEnabled() as $sheet) {
            try {
                $this->syncService->syncSheet($sheet);
            } catch (\Throwable $e) {
                $this->logger->error('File drop batch: scheduled Google Sheet sync failed', [
                    'app' => Application::APP_ID,
                    'exception' => $e,
                ]);
                // Already recorded on the sheet itself by SheetSyncService; move on to the next one.
            }
        }
    }
}
