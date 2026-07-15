<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCA\FileDropBatch\Db\Batch;
use OCA\FileDropBatch\Db\BatchMapper;
use OCA\FileDropBatch\Db\Session;
use OCA\FileDropBatch\Db\SessionMapper;
use OCP\Constants;
use Psr\Log\LoggerInterface;

class BatchProcessorService {
    private const THEATRE_USER_PERMISSIONS = Constants::PERMISSION_READ
        | Constants::PERMISSION_UPDATE
        | Constants::PERMISSION_CREATE
        | Constants::PERMISSION_DELETE;

    public function __construct(
        private CsvReader $csvReader,
        private CsvWriter $csvWriter,
        private FolderService $folderService,
        private ShareService $shareService,
        private UserService $userService,
        private SessionService $sessionService,
        private BatchMapper $batchMapper,
        private SessionMapper $sessionMapper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Parses and validates the batch expiry date from the form field (an HTML
     * "date" input value, e.g. "2026-08-01").
     *
     * Nextcloud's public link share expiration is date-only: OC\Share20\Manager
     * truncates both the expiration date and "now" to midnight before comparing,
     * so any time-of-day is ignored and the share effectively expires at the
     * very start (00:00) of the chosen date. Today is therefore always rejected
     * as "in the past" by Nextcloud itself - the earliest valid expiry is tomorrow.
     *
     * @throws \InvalidArgumentException
     */
    public function parseExpiry(string $raw): \DateTimeImmutable {
        if (trim($raw) === '') {
            throw new \InvalidArgumentException('An expiry date is required');
        }

        $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($expiry === false) {
            throw new \InvalidArgumentException('Could not understand the expiry date');
        }

        $today = new \DateTimeImmutable('today');
        if ($expiry <= $today) {
            throw new \InvalidArgumentException('The expiry date must be at least tomorrow (Nextcloud link shares expire at day granularity, so today is always in the past)');
        }

        return $expiry;
    }

    /**
     * @param string[] $rootFolderNames predefined/custom folder names created once under $baseFolder
     * @return array{
     *   summary: array<string, int>, rows: array<int, array<string, mixed>>, csv: string,
     *   userSummary: array<string, int>, users: array<int, array<string, mixed>>, usersCsv: string
     * }
     * @throws \RuntimeException if the CSV itself is malformed (missing headers, unreadable).
     */
    public function processBatch(
        string $userId,
        string $csvPath,
        \DateTimeInterface $expiry,
        string $baseFolder,
        array $rootFolderNames = [],
        bool $createUsers = false,
    ): array {
        $inputRows = $this->csvReader->read($csvPath);

        $rootFolders = $this->createRootFolders($userId, $baseFolder, $rootFolderNames);

        $userResults = [];
        $userSummary = ['total' => 0, 'created' => 0, 'existing' => 0, 'error' => 0];
        if ($createUsers) {
            foreach ($this->distinctTheatres($inputRows) as $theatre) {
                $userResult = $this->processTheatreUser($userId, $baseFolder, $theatre, $rootFolders);
                $userResults[] = $userResult;
                $userSummary['total']++;
                $userSummary[$userResult['status']]++;
            }
        }

        $resultRows = [];
        $summary = ['total' => 0, 'success' => 0, 'partial' => 0, 'error' => 0];
        foreach ($inputRows as $inputRow) {
            $result = $this->processRow($userId, $inputRow, $expiry, $baseFolder);
            $resultRows[] = $result;
            $summary['total']++;
            $summary[$result['status']]++;
        }

        if ($summary['success'] + $summary['partial'] > 0) {
            $this->persistBatchAndSessions($userId, $baseFolder, $expiry, $resultRows);
        }

        return [
            'summary' => $summary,
            'rows' => $resultRows,
            'csv' => $this->csvWriter->write($resultRows),
            'userSummary' => $userSummary,
            'users' => $userResults,
            'usersCsv' => $createUsers ? $this->csvWriter->writeUsers($userResults) : '',
        ];
    }

    /** @param array<int, array<string, mixed>> $resultRows */
    private function persistBatchAndSessions(string $userId, string $baseFolder, \DateTimeInterface $expiry, array $resultRows): void {
        try {
            $batch = $this->batchMapper->insertBatch($userId, PathSanitizer::sanitizeSegment($baseFolder), $expiry);
        } catch (\Throwable $e) {
            $this->logger->error('File drop batch: could not record batch for scheduled sync', ['app' => 'filedropbatch', 'exception' => $e]);
            return;
        }

        foreach ($resultRows as $row) {
            if ($row['status'] !== 'success' && $row['status'] !== 'partial') {
                continue;
            }

            try {
                $session = new Session();
                $session->setBatchId($batch->getId());
                $session->setUserId($userId);
                $session->setTheatre($row['theatre']);
                $session->setDate($row['date']);
                $session->setStartTime($row['startTime']);
                $session->setPresenterName($row['presenterName']);
                $session->setPresenterEmail($row['presenterEmail']);
                $session->setBaseFolder($baseFolder);
                $session->setFolderPath($row['folderPath']);
                $session->setShareId($row['shareId']);
                $session->setStatus(Session::STATUS_OPEN);
                $session->setEmailSent($row['emailSent']);
                $session->setCreatedAt(new \DateTime());
                $this->sessionMapper->insert($session);
            } catch (\Throwable $e) {
                $this->logger->error('File drop batch: could not record session', ['app' => 'filedropbatch', 'exception' => $e]);
            }
        }
    }

    /**
     * @param string[] $rootFolderNames
     * @return \OCP\Files\Folder[]
     */
    private function createRootFolders(string $userId, string $baseFolder, array $rootFolderNames): array {
        $baseSegment = PathSanitizer::sanitizeSegment($baseFolder);
        $folders = [];

        foreach ($rootFolderNames as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $folders[] = $this->folderService->getOrCreatePath($userId, [$baseSegment, PathSanitizer::sanitizeSegment($name)]);
        }

        return $folders;
    }

    /** @return string[] distinct, non-blank theatre names in CSV row order */
    private function distinctTheatres(array $inputRows): array {
        $seen = [];
        foreach ($inputRows as $row) {
            $theatre = trim($row['theatre'] ?? '');
            if ($theatre !== '') {
                $seen[$theatre] = true;
            }
        }
        return array_keys($seen);
    }

    /** @param \OCP\Files\Folder[] $rootFolders */
    private function processTheatreUser(string $userId, string $baseFolder, string $theatre, array $rootFolders): array {
        $result = [
            'theatre' => $theatre,
            'username' => '',
            'password' => '',
            'status' => 'error',
            'message' => '',
        ];

        try {
            $theatreFolder = $this->folderService->getOrCreatePath($userId, [
                PathSanitizer::sanitizeSegment($baseFolder),
                PathSanitizer::sanitizeSegment($theatre),
            ]);

            $account = $this->userService->createOrGetTheatreUser($theatre);
            $result['username'] = $account['username'];
            $result['password'] = $account['password'] ?? '';
            $result['status'] = $account['created'] ? 'created' : 'existing';

            $this->shareService->ensureUserShare($theatreFolder, $userId, $account['username'], self::THEATRE_USER_PERMISSIONS);
            foreach ($rootFolders as $rootFolder) {
                $this->shareService->ensureUserShare($rootFolder, $userId, $account['username'], self::THEATRE_USER_PERMISSIONS);
            }
        } catch (\Throwable $e) {
            $this->logger->error('File drop batch: theatre user setup failed', ['app' => 'filedropbatch', 'exception' => $e]);
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    /** @param array<string, string> $inputRow */
    private function processRow(string $userId, array $inputRow, \DateTimeInterface $expiry, string $baseFolder): array {
        $date = $inputRow['date'];
        $theatre = $inputRow['theatre'];
        $startTime = $inputRow['start time'];
        $presenterName = $inputRow['presenter name'];
        $presenterEmail = $inputRow['presenter email'];
        $rowNumber = (int)$inputRow['_rowNumber'];

        $result = [
            'rowNumber' => $rowNumber,
            'date' => $date,
            'theatre' => $theatre,
            'startTime' => $startTime,
            'presenterName' => $presenterName,
            'presenterEmail' => $presenterEmail,
            'status' => 'error',
            'message' => '',
            'folderPath' => '',
            'shareLink' => '',
            'shareId' => '',
            'emailSent' => false,
        ];

        $validationError = $this->sessionService->validateFields($date, $theatre, $startTime, $presenterName);
        if ($validationError !== null) {
            $result['message'] = $validationError;
            return $result;
        }

        $created = $this->sessionService->createSession($userId, $baseFolder, $theatre, $date, $startTime, $presenterName, $presenterEmail, $expiry);

        return array_merge($result, $created);
    }
}
