<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Controller;

use OCA\FileDropBatch\Db\BatchMapper;
use OCA\FileDropBatch\Db\Session;
use OCA\FileDropBatch\Db\SessionMapper;
use OCA\FileDropBatch\Service\BatchProcessorService;
use OCA\FileDropBatch\Service\PathSanitizer;
use OCA\FileDropBatch\Service\SessionService;
use OCA\FileDropBatch\Service\ShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Manages persisted sessions (list/create/edit/close/delete). Every action is
 * scoped to the current user's own sessions - SessionMapper::findOwned()
 * returns not-found rather than someone else's row.
 */
class SessionController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private SessionMapper $sessionMapper,
        private BatchMapper $batchMapper,
        private SessionService $sessionService,
        private ShareService $shareService,
        private BatchProcessorService $batchProcessor,
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

        $sessions = $this->sessionMapper->findByUser($uid);

        return new DataResponse(['sessions' => array_map([$this, 'toApiRow'], $sessions)]);
    }

    #[NoAdminRequired]
    public function create(): DataResponse {
        $uid = $this->requireUserId();
        if ($uid === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        $theatre = trim((string)$this->request->getParam('theatre', ''));
        $date = trim((string)$this->request->getParam('date', ''));
        $startTime = trim((string)$this->request->getParam('start_time', ''));
        $presenterName = trim((string)$this->request->getParam('presenter_name', ''));
        $presenterEmail = trim((string)$this->request->getParam('presenter_email', ''));
        $baseFolder = trim((string)$this->request->getParam('base_folder', 'File Drops'));
        if ($baseFolder === '') {
            $baseFolder = 'File Drops';
        }
        $expiryRaw = (string)$this->request->getParam('expiry_date', '');

        $validationError = $this->sessionService->validateFields($date, $theatre, $startTime, $presenterName);
        if ($validationError !== null) {
            return new DataResponse(['error' => $validationError], Http::STATUS_BAD_REQUEST);
        }

        try {
            $expiry = $this->batchProcessor->parseExpiry($expiryRaw);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $created = $this->sessionService->createSession($uid, $baseFolder, $theatre, $date, $startTime, $presenterName, $presenterEmail, $expiry);
        if ($created['status'] === 'error') {
            return new DataResponse(['error' => $created['message']], Http::STATUS_BAD_REQUEST);
        }

        $batch = $this->batchMapper->insertBatch($uid, PathSanitizer::sanitizeSegment($baseFolder), $expiry);

        $session = new Session();
        $session->setBatchId($batch->getId());
        $session->setUserId($uid);
        $session->setTheatre($theatre);
        $session->setDate($date);
        $session->setStartTime($startTime);
        $session->setPresenterName($presenterName);
        $session->setPresenterEmail($presenterEmail);
        $session->setBaseFolder($baseFolder);
        $session->setFolderPath($created['folderPath']);
        $session->setShareId($created['shareId']);
        $session->setStatus(Session::STATUS_OPEN);
        $session->setEmailSent($created['emailSent']);
        $session->setCreatedAt(new \DateTime());
        $session = $this->sessionMapper->insert($session);

        return new DataResponse(['session' => $this->toApiRow($session), 'message' => $created['message']]);
    }

    #[NoAdminRequired]
    public function update(int $id): DataResponse {
        $uid = $this->requireUserId();
        if ($uid === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $session = $this->sessionMapper->findOwned($id, $uid);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Session not found'], Http::STATUS_NOT_FOUND);
        }

        $theatre = trim((string)$this->request->getParam('theatre', ''));
        $date = trim((string)$this->request->getParam('date', ''));
        $startTime = trim((string)$this->request->getParam('start_time', ''));
        $presenterName = trim((string)$this->request->getParam('presenter_name', ''));

        $validationError = $this->sessionService->validateFields($date, $theatre, $startTime, $presenterName);
        if ($validationError !== null) {
            return new DataResponse(['error' => $validationError], Http::STATUS_BAD_REQUEST);
        }

        try {
            $session = $this->sessionService->renameSession($session, $theatre, $date, $startTime, $presenterName);
        } catch (\Throwable $e) {
            $this->logger->error('File drop batch: session rename failed', ['app' => 'filedropbatch', 'exception' => $e]);
            return new DataResponse(['error' => 'Could not move the folder: ' . $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $this->sessionMapper->update($session);

        return new DataResponse(['session' => $this->toApiRow($session)]);
    }

    #[NoAdminRequired]
    public function close(int $id): DataResponse {
        $uid = $this->requireUserId();
        if ($uid === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $session = $this->sessionMapper->findOwned($id, $uid);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Session not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $this->sessionService->closeSession($session);
        } catch (\Throwable $e) {
            $this->logger->error('File drop batch: session close failed', ['app' => 'filedropbatch', 'exception' => $e]);
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $session->setStatus(Session::STATUS_CLOSED);
        $session->setClosedAt(new \DateTime());
        $this->sessionMapper->update($session);

        return new DataResponse(['session' => $this->toApiRow($session)]);
    }

    #[NoAdminRequired]
    public function destroy(int $id): DataResponse {
        $uid = $this->requireUserId();
        if ($uid === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $session = $this->sessionMapper->findOwned($id, $uid);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Session not found'], Http::STATUS_NOT_FOUND);
        }

        // Untrack only - the real folder, its files, and its share (if still
        // open) are deliberately left completely alone.
        $this->sessionMapper->delete($session);

        return new DataResponse([]);
    }

    private function requireUserId(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    private function toApiRow(Session $session): array {
        $shareLink = '';
        if ($session->getStatus() === Session::STATUS_OPEN) {
            $share = $this->shareService->findByFullId($session->getShareId());
            if ($share !== null) {
                $shareLink = $this->shareService->getPublicUrl($share);
            }
        }

        return [
            'id' => $session->getId(),
            'theatre' => $session->getTheatre(),
            'date' => $session->getDate(),
            'startTime' => $session->getStartTime(),
            'presenterName' => $session->getPresenterName(),
            'presenterEmail' => $session->getPresenterEmail(),
            'status' => $session->getStatus(),
            'shareLink' => $shareLink,
            'emailSent' => $session->getEmailSent(),
        ];
    }
}
