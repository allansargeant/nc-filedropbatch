<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCA\FileDropBatch\Db\BatchMapper;
use OCA\FileDropBatch\Db\Session;
use OCA\FileDropBatch\Db\SessionMapper;
use OCA\FileDropBatch\Db\Sheet;
use OCA\FileDropBatch\Db\SheetMapper;
use Psr\Log\LoggerInterface;

/**
 * Reconciles a linked Google Sheet's current rows against its previously
 * synced sessions, matching by the natural key Theatre+Date+Start Time (no
 * "ID" column needed in the sheet). A row that has vanished from the sheet
 * automatically closes its session's file-drop link immediately - the same
 * as clicking "Close now" - with no confirmation and no undo, so this is
 * intentionally more aggressive than session deletion elsewhere in the app.
 */
class SheetSyncService {
    public function __construct(
        private GoogleSheetsService $googleSheets,
        private SessionService $sessionService,
        private SessionMapper $sessionMapper,
        private BatchMapper $batchMapper,
        private SheetMapper $sheetMapper,
        private BatchProcessorService $batchProcessor,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws \RuntimeException if the sheet's rows can't be fetched at all */
    public function syncSheet(Sheet $sheet): void {
        try {
            $rows = $this->googleSheets->fetchRows($sheet->getSpreadsheetId());
        } catch (\Throwable $e) {
            $this->recordResult($sheet, 'error', $e->getMessage());
            throw $e;
        }

        $currentByKey = [];
        foreach ($rows as $row) {
            $currentByKey[$this->naturalKey($row['theatre'], $row['date'], $row['start time'])] = $row;
        }

        $created = 0;
        $renamed = 0;
        $closed = 0;
        $unchanged = 0;
        $errors = [];

        // Mirrors processBatch()'s CSV/manual behaviour: (re)ensure the sheet's
        // configured root folders and, if enabled, a theatre user account for
        // every distinct theatre currently in the sheet - idempotent, so this
        // runs every sync rather than only when a session is first created.
        $rootFolderNames = array_values(array_filter(array_map('trim', explode(',', $sheet->getRootFolderNames()))));
        $userResults = $this->batchProcessor->ensureRootFoldersAndTheatreUsers(
            $sheet->getUserId(),
            $sheet->getBaseFolder(),
            $rootFolderNames,
            $this->batchProcessor->distinctTheatres($rows),
            $sheet->getCreateUsers(),
        );
        foreach ($userResults as $userResult) {
            if ($userResult['status'] === 'error') {
                $errors[] = "Could not set up theatre account for \"{$userResult['theatre']}\": {$userResult['message']}";
            }
        }

        foreach ($this->sessionMapper->findOpenBySheet($sheet->getId()) as $session) {
            $key = $this->naturalKey($session->getTheatre(), $session->getDate(), $session->getStartTime());

            if (!isset($currentByKey[$key])) {
                try {
                    $this->sessionService->closeSession($session);
                    $session->setStatus(Session::STATUS_CLOSED);
                    $session->setClosedAt(new \DateTime());
                    $this->sessionMapper->update($session);
                    $closed++;
                } catch (\Throwable $e) {
                    $this->logger->error('File drop batch: sheet sync auto-close failed', ['app' => 'filedropbatch', 'exception' => $e]);
                    $errors[] = "Could not auto-close \"{$session->getTheatre()}\": {$e->getMessage()}";
                }
                continue;
            }

            $row = $currentByKey[$key];
            unset($currentByKey[$key]);

            $nameChanged = $row['presenter name'] !== $session->getPresenterName();
            $emailChanged = $row['presenter email'] !== $session->getPresenterEmail();
            if (!$nameChanged && !$emailChanged) {
                $unchanged++;
                continue;
            }

            try {
                if ($nameChanged) {
                    $session = $this->sessionService->renameSession(
                        $session,
                        $session->getTheatre(),
                        $session->getDate(),
                        $session->getStartTime(),
                        $row['presenter name'],
                    );
                    $renamed++;
                }
                if ($emailChanged) {
                    $session->setPresenterEmail($row['presenter email']);
                }
                $this->sessionMapper->update($session);
            } catch (\Throwable $e) {
                $this->logger->error('File drop batch: sheet sync edit failed', ['app' => 'filedropbatch', 'exception' => $e]);
                $errors[] = "Could not update \"{$session->getTheatre()}\": {$e->getMessage()}";
            }
        }

        // Whatever's left in currentByKey is genuinely new - no open session matched that key.
        if ($currentByKey !== []) {
            try {
                $expiry = $this->batchProcessor->parseExpiry($sheet->getExpiryDate());
            } catch (\InvalidArgumentException $e) {
                $errors[] = 'Could not create ' . count($currentByKey) . ' new session(s): ' . $e->getMessage()
                    . ' - update this linked sheet\'s expiry date.';
                $currentByKey = [];
            }

            foreach ($currentByKey as $row) {
                $result = $this->createSessionFromRow($sheet, $row, $expiry ?? null);
                if ($result === null) {
                    continue;
                }
                if ($result['status'] === 'error') {
                    $errors[] = "Could not create \"{$row['theatre']}\": {$result['message']}";
                } else {
                    $created++;
                }
            }
        }

        $message = "Created $created, renamed $renamed, closed $closed, unchanged $unchanged.";
        if ($errors !== []) {
            $message .= ' Errors: ' . implode(' | ', $errors);
        }
        $this->recordResult($sheet, $errors === [] ? 'success' : 'partial', $message);
    }

    /** @param array<string, string> $row canonical row from GoogleSheetsService::fetchRows() */
    private function createSessionFromRow(Sheet $sheet, array $row, ?\DateTimeInterface $expiry): ?array {
        if ($expiry === null) {
            return null;
        }

        $theatre = $row['theatre'];
        $date = $row['date'];
        $startTime = $row['start time'];
        $presenterName = $row['presenter name'];
        $presenterEmail = $row['presenter email'];

        $validationError = $this->sessionService->validateFields($date, $theatre, $startTime, $presenterName);
        if ($validationError !== null) {
            return ['status' => 'error', 'message' => $validationError];
        }

        $result = $this->sessionService->createSession(
            $sheet->getUserId(),
            $sheet->getBaseFolder(),
            $theatre,
            $date,
            $startTime,
            $presenterName,
            $presenterEmail,
            $expiry,
        );

        if ($result['status'] === 'error') {
            return $result;
        }

        try {
            $batch = $this->batchMapper->insertBatch($sheet->getUserId(), PathSanitizer::sanitizeSegment($sheet->getBaseFolder()), $expiry);

            $session = new Session();
            $session->setBatchId($batch->getId());
            $session->setUserId($sheet->getUserId());
            $session->setTheatre($theatre);
            $session->setDate($date);
            $session->setStartTime($startTime);
            $session->setPresenterName($presenterName);
            $session->setPresenterEmail($presenterEmail);
            $session->setBaseFolder($sheet->getBaseFolder());
            $session->setFolderPath($result['folderPath']);
            $session->setShareId($result['shareId']);
            $session->setStatus(Session::STATUS_OPEN);
            $session->setEmailSent($result['emailSent']);
            $session->setCreatedAt(new \DateTime());
            $session->setSheetId($sheet->getId());
            $this->sessionMapper->insert($session);
        } catch (\Throwable $e) {
            $this->logger->error('File drop batch: could not record sheet-synced session', ['app' => 'filedropbatch', 'exception' => $e]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        return $result;
    }

    private function naturalKey(string $theatre, string $date, string $startTime): string {
        return strtolower(trim($theatre)) . '|' . strtolower(trim($date)) . '|' . strtolower(trim($startTime));
    }

    private function recordResult(Sheet $sheet, string $status, string $message): void {
        $sheet->setLastSyncedAt(new \DateTime());
        $sheet->setLastSyncStatus($status);
        $sheet->setLastSyncMessage($message);
        $this->sheetMapper->update($sheet);
    }
}
