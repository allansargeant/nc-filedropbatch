<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Settings;

use OCA\FileDropBatch\AppInfo\Application;
use OCA\FileDropBatch\Service\RcloneSyncService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function __construct(
        private RcloneSyncService $syncService,
    ) {
    }

    public function getForm(): TemplateResponse {
        return new TemplateResponse(Application::APP_ID, 'admin', $this->syncService->getSettings());
    }

    public function getSection(): ?string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
