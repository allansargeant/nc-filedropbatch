<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\BackgroundJob;

use OCA\FileDropBatch\AppInfo\Application;
use OCA\FileDropBatch\Db\BatchMapper;
use OCA\FileDropBatch\Service\RcloneSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodically syncs the base folder of any batch whose expiry has passed
 * and that hasn't been synced yet to the configured remote site-server.
 */
class SyncExpiredBatchesJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private BatchMapper $batchMapper,
        private RcloneSyncService $syncService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
        // TIME_SENSITIVE (the default) - this job is cheap (usually a no-op
        // query) and admins reasonably expect a sync to start promptly after
        // expiry, not be arbitrarily deferred the way TIME_INSENSITIVE jobs can be.
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }

    protected function run($argument): void {
        if (!$this->syncService->isEnabled() || !$this->syncService->isConfigured()) {
            return;
        }

        $due = $this->batchMapper->findDue();
        if ($due === []) {
            return;
        }

        $groups = [];
        foreach ($due as $batch) {
            $key = $batch->getUserId() . '::' . $batch->getBaseFolder();
            $groups[$key]['userId'] = $batch->getUserId();
            $groups[$key]['baseFolder'] = $batch->getBaseFolder();
            $groups[$key]['ids'][] = $batch->getId();
        }

        foreach ($groups as $group) {
            try {
                $this->syncService->syncBaseFolder($group['userId'], $group['baseFolder']);
                $this->batchMapper->markSynced($group['ids']);
            } catch (\Throwable $e) {
                $this->logger->error('File drop batch: scheduled sync failed', [
                    'app' => Application::APP_ID,
                    'exception' => $e,
                ]);
                // Leave unsynced; retried on the next run.
            }
        }
    }
}
