<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Controller;

use OCA\FileDropBatch\AppInfo\Application;
use OCA\FileDropBatch\Service\BatchProcessorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

class BatchController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IConfig $config,
        private BatchProcessorService $processor,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function process(): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        $uploaded = $this->request->getUploadedFile('csv_file');
        if ($uploaded === null || !is_uploaded_file($uploaded['tmp_name'] ?? '')) {
            return new DataResponse(['error' => 'No CSV file was uploaded'], Http::STATUS_BAD_REQUEST);
        }

        $expiryRaw = (string)$this->request->getParam('expiry_date', '');
        $baseFolder = trim((string)$this->request->getParam('base_folder', 'File Drops'));
        if ($baseFolder === '') {
            $baseFolder = 'File Drops';
        }

        $rootFolderNames = $this->collectRootFolderNames();
        $createUsers = $this->isChecked($this->request->getParam('create_users', ''));

        if ($createUsers && !$this->canManageTheatreUsers($user)) {
            return new DataResponse([
                'error' => 'Only admins or subadmins can create theatre user accounts',
            ], Http::STATUS_FORBIDDEN);
        }

        try {
            $expiry = $this->processor->parseExpiry($expiryRaw);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->processor->processBatch(
                $user->getUID(),
                $uploaded['tmp_name'],
                $expiry,
                $baseFolder,
                $rootFolderNames,
                $createUsers,
            );
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $this->config->setUserValue($user->getUID(), Application::APP_ID, 'base_folder', $baseFolder);

        $response = [
            'summary' => $result['summary'],
            'rows' => $result['rows'],
            'csvBase64' => base64_encode($result['csv']),
            'csvFilename' => 'filedrop-results-' . date('Ymd-His') . '.csv',
        ];

        if ($createUsers) {
            $response['userSummary'] = $result['userSummary'];
            $response['users'] = $result['users'];
            $response['usersCsvBase64'] = base64_encode($result['usersCsv']);
            $response['usersCsvFilename'] = 'filedrop-theatre-accounts-' . date('Ymd-His') . '.csv';
        }

        return new DataResponse($response);
    }

    /** @return string[] */
    private function collectRootFolderNames(): array {
        $predefined = (array)$this->request->getParam('root_folders', []);
        $custom = (string)$this->request->getParam('custom_folders', '');

        $names = array_map('strval', $predefined);
        foreach (explode(',', $custom) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique(array_filter($names, fn ($n) => trim($n) !== '')));
    }

    private function isChecked(mixed $value): bool {
        return in_array((string)$value, ['1', 'true', 'on'], true);
    }

    private function canManageTheatreUsers(IUser $user): bool {
        if ($this->groupManager->isAdmin($user->getUID())) {
            return true;
        }

        // OCP\IGroupManager::getSubAdmin() was only made public API in newer
        // Nextcloud versions - guard against older instances that lack it.
        if (method_exists($this->groupManager, 'getSubAdmin')) {
            return $this->groupManager->getSubAdmin()->isSubAdmin($user);
        }

        return false;
    }
}
