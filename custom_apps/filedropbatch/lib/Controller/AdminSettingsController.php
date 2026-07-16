<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Controller;

use OCA\FileDropBatch\Service\GoogleAuthService;
use OCA\FileDropBatch\Service\RcloneSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * No #[NoAdminRequired] attribute anywhere in this controller - the
 * AppFramework's default (admin-only) applies to every method here, since
 * remote sync destination credentials are an instance-wide concern.
 */
class AdminSettingsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private RcloneSyncService $syncService,
        private GoogleAuthService $googleAuth,
    ) {
        parent::__construct($appName, $request);
    }

    public function saveGoogle(): DataResponse {
        $clientId = trim((string)$this->request->getParam('google_client_id', ''));
        $clientSecret = (string)$this->request->getParam('google_client_secret', '');

        $this->googleAuth->saveClientCredentials($clientId, $clientSecret !== '' ? $clientSecret : null);

        return new DataResponse(['google' => $this->googleAuth->getSettings()]);
    }

    public function save(): DataResponse {
        $remoteUrl = trim((string)$this->request->getParam('remote_url', ''));
        $remoteUser = trim((string)$this->request->getParam('remote_user', ''));
        $remotePassword = (string)$this->request->getParam('remote_password', '');
        $remoteBasePath = trim((string)$this->request->getParam('remote_base_path', ''));
        $rcloneBinary = trim((string)$this->request->getParam('rclone_binary', 'rclone'));
        $localBaseUrl = trim((string)$this->request->getParam('local_base_url', ''));
        $syncEnabled = $this->isChecked($this->request->getParam('sync_enabled', ''));

        $this->syncService->saveSettings(
            $remoteUrl,
            $remoteUser,
            $remotePassword !== '' ? $remotePassword : null,
            $remoteBasePath,
            $rcloneBinary,
            $localBaseUrl,
            $syncEnabled,
        );

        return new DataResponse(['settings' => $this->syncService->getSettings()]);
    }

    public function syncNow(): DataResponse {
        $baseFolder = trim((string)$this->request->getParam('base_folder', 'File Drops'));
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        // A manual, admin-initiated sync - allow it more time than a typical web request.
        set_time_limit(300);

        try {
            $this->syncService->syncBaseFolder($user->getUID(), $baseFolder !== '' ? $baseFolder : 'File Drops');
        } catch (\Throwable $e) {
            return new DataResponse([
                'error' => $e->getMessage(),
                'settings' => $this->syncService->getSettings(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new DataResponse(['settings' => $this->syncService->getSettings()]);
    }

    private function isChecked(mixed $value): bool {
        return in_array((string)$value, ['1', 'true', 'on'], true);
    }
}
