<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Controller;

use OCA\FileDropBatch\Db\SessionMapper;
use OCA\FileDropBatch\Db\Sheet;
use OCA\FileDropBatch\Db\SheetMapper;
use OCA\FileDropBatch\Service\BatchProcessorService;
use OCA\FileDropBatch\Service\GoogleAuthService;
use OCA\FileDropBatch\Service\GoogleSheetsService;
use OCA\FileDropBatch\Service\SheetSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Manages linked Google Sheets (list/link/edit/unlink/sync-now), scoped to
 * the current user's own links - same ownership pattern as SessionController.
 * Unlinking only removes the Sheet row and forgets its sessions ever came
 * from it; the sessions, their folders, and their shares are left alone.
 */
class SheetController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private SheetMapper $sheetMapper,
        private GoogleAuthService $googleAuth,
        private GoogleSheetsService $googleSheets,
        private BatchProcessorService $batchProcessor,
        private SheetSyncService $sheetSyncService,
        private SessionMapper $sessionMapper,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(): DataResponse {
        $uid = $this->requireUserId();
        if ($uid === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        $sheets = $this->sheetMapper->findByUser($uid);

        return new DataResponse([
            'connected' => $this->googleAuth->isConnected(),
            'sheets' => array_map([$this, 'toApiRow'], $sheets),
        ]);
    }

    #[NoAdminRequired]
    public function create(): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->googleAuth->isConnected()) {
            return new DataResponse(['error' => 'Google is not connected - ask an admin to connect it first'], Http::STATUS_FORBIDDEN);
        }

        $name = trim((string)$this->request->getParam('name', ''));
        $sheetUrl = trim((string)$this->request->getParam('sheet_url', ''));
        $baseFolder = trim((string)$this->request->getParam('base_folder', 'File Drops'));
        if ($baseFolder === '') {
            $baseFolder = 'File Drops';
        }
        $expiryRaw = (string)$this->request->getParam('expiry_date', '');
        $rootFolderNames = $this->collectRootFolderNames();
        $createUsers = $this->isChecked($this->request->getParam('create_users', ''));
        $syncEnabled = $this->isChecked($this->request->getParam('sync_enabled', '1'));

        if ($name === '') {
            return new DataResponse(['error' => 'A name is required'], Http::STATUS_BAD_REQUEST);
        }
        if ($createUsers && !$this->canManageTheatreUsers($user)) {
            return new DataResponse(['error' => 'Only admins or subadmins can create theatre user accounts'], Http::STATUS_FORBIDDEN);
        }

        $spreadsheetId = $this->googleSheets->extractSpreadsheetId($sheetUrl);
        if ($spreadsheetId === null) {
            return new DataResponse(['error' => 'Could not find a spreadsheet ID in that URL'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->batchProcessor->parseExpiry($expiryRaw);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $sheet = new Sheet();
        $sheet->setUserId($user->getUID());
        $sheet->setName($name);
        $sheet->setSheetUrl($sheetUrl);
        $sheet->setSpreadsheetId($spreadsheetId);
        $sheet->setBaseFolder($baseFolder);
        $sheet->setExpiryDate($expiryRaw);
        $sheet->setRootFolderNames(implode(',', $rootFolderNames));
        $sheet->setCreateUsers($createUsers);
        $sheet->setSyncEnabled($syncEnabled);
        $sheet->setLastSyncStatus('');
        $sheet->setLastSyncMessage('');
        $sheet->setCreatedAt(new \DateTime());
        $sheet = $this->sheetMapper->insert($sheet);

        return new DataResponse(['sheet' => $this->toApiRow($sheet)]);
    }

    #[NoAdminRequired]
    public function update(int $id): DataResponse {
        $uid = $this->requireUserId();
        if ($uid === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $sheet = $this->sheetMapper->findOwned($id, $uid);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Linked sheet not found'], Http::STATUS_NOT_FOUND);
        }

        $name = trim((string)$this->request->getParam('name', $sheet->getName()));
        $baseFolder = trim((string)$this->request->getParam('base_folder', $sheet->getBaseFolder()));
        $expiryRaw = (string)$this->request->getParam('expiry_date', $sheet->getExpiryDate());
        $syncEnabled = $this->isChecked($this->request->getParam('sync_enabled', $sheet->getSyncEnabled() ? '1' : ''));

        try {
            $this->batchProcessor->parseExpiry($expiryRaw);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $sheet->setName($name !== '' ? $name : $sheet->getName());
        $sheet->setBaseFolder($baseFolder !== '' ? $baseFolder : $sheet->getBaseFolder());
        $sheet->setExpiryDate($expiryRaw);
        $sheet->setSyncEnabled($syncEnabled);
        $this->sheetMapper->update($sheet);

        return new DataResponse(['sheet' => $this->toApiRow($sheet)]);
    }

    #[NoAdminRequired]
    public function destroy(int $id): DataResponse {
        $uid = $this->requireUserId();
        if ($uid === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $sheet = $this->sheetMapper->findOwned($id, $uid);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Linked sheet not found'], Http::STATUS_NOT_FOUND);
        }

        // Unlink only - forgets which sheet created these sessions, but the
        // sessions, their folders, and their shares are left completely alone.
        $this->sessionMapper->clearSheetId($sheet->getId());
        $this->sheetMapper->delete($sheet);

        return new DataResponse([]);
    }

    #[NoAdminRequired]
    public function syncNow(int $id): DataResponse {
        $uid = $this->requireUserId();
        if ($uid === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $sheet = $this->sheetMapper->findOwned($id, $uid);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Linked sheet not found'], Http::STATUS_NOT_FOUND);
        }

        set_time_limit(120);

        try {
            $this->sheetSyncService->syncSheet($sheet);
        } catch (\Throwable $e) {
            $this->logger->error('File drop batch: manual sheet sync failed', ['app' => 'filedropbatch', 'exception' => $e]);
            return new DataResponse([
                'error' => $e->getMessage(),
                'sheet' => $this->toApiRow($sheet),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new DataResponse(['sheet' => $this->toApiRow($sheet)]);
    }

    private function requireUserId(): ?string {
        return $this->userSession->getUser()?->getUID();
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
        if (method_exists($this->groupManager, 'getSubAdmin')) {
            return $this->groupManager->getSubAdmin()->isSubAdmin($user);
        }
        return false;
    }

    private function toApiRow(Sheet $sheet): array {
        return [
            'id' => $sheet->getId(),
            'name' => $sheet->getName(),
            'sheetUrl' => $sheet->getSheetUrl(),
            'baseFolder' => $sheet->getBaseFolder(),
            'expiryDate' => $sheet->getExpiryDate(),
            'createUsers' => $sheet->getCreateUsers(),
            'syncEnabled' => $sheet->getSyncEnabled(),
            'lastSyncedAt' => $sheet->getLastSyncedAt()?->format(DATE_ATOM),
            'lastSyncStatus' => $sheet->getLastSyncStatus(),
            'lastSyncMessage' => $sheet->getLastSyncMessage(),
        ];
    }
}
