<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\AppInfo;

use OCA\FileDropBatch\BackgroundJob\SyncExpiredBatchesJob;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;

class Application extends App implements IBootstrap {
    public const APP_ID = 'filedropbatch';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
    }

    public function boot(IBootContext $context): void {
        // IRegistrationContext::registerBackgroundJob() isn't available on
        // every supported Nextcloud version (27-31), so register directly
        // via IJobList - it's a no-op if already registered, safe to call
        // on every request.
        $context->getAppContainer()->get(IJobList::class)->add(SyncExpiredBatchesJob::class);
    }
}
