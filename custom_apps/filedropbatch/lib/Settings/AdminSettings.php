<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Settings;

use OCA\FileDropBatch\AppInfo\Application;
use OCA\FileDropBatch\Service\GoogleAuthService;
use OCA\FileDropBatch\Service\RcloneSyncService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function __construct(
        private RcloneSyncService $syncService,
        private GoogleAuthService $googleAuth,
    ) {
    }

    public function getForm(): TemplateResponse {
        $params = array_merge($this->syncService->getSettings(), [
            'google' => $this->googleAuth->getSettings(),
        ]);

        return new TemplateResponse(Application::APP_ID, 'admin', $params);
    }

    public function getSection(): ?string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
