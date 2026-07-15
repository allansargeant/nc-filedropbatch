<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCP\Constants;
use Psr\Log\LoggerInterface;

class BatchProcessorService {
    private const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd M Y', 'j M Y'];
    private const TIME_FORMATS = ['H:i', 'H:i:s', 'g:ia', 'g:i a', 'g:i A', 'g.ia'];
    private const THEATRE_USER_PERMISSIONS = Constants::PERMISSION_READ
        | Constants::PERMISSION_UPDATE
        | Constants::PERMISSION_CREATE
        | Constants::PERMISSION_DELETE;

    public function __construct(
        private CsvReader $csvReader,
        private CsvWriter $csvWriter,
        private FolderService $folderService,
        private ShareService $shareService,
        private MailService $mailService,
        private UserService $userService,
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

        return [
            'summary' => $summary,
            'rows' => $resultRows,
            'csv' => $this->csvWriter->write($resultRows),
            'userSummary' => $userSummary,
            'users' => $userResults,
            'usersCsv' => $createUsers ? $this->csvWriter->writeUsers($userResults) : '',
        ];
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
            'emailSent' => false,
        ];

        foreach (['date' => $date, 'theatre' => $theatre, 'start time' => $startTime, 'presenter name' => $presenterName] as $field => $value) {
            if ($value === '') {
                $result['message'] = "Missing required field: $field";
                return $result;
            }
        }

        if ($this->parseDate($date) === null) {
            $result['message'] = "Unrecognized date format: \"$date\"";
            return $result;
        }
        if ($this->parseTime($startTime) === null) {
            $result['message'] = "Unrecognized time format: \"$startTime\"";
            return $result;
        }

        $emailValid = filter_var($presenterEmail, FILTER_VALIDATE_EMAIL) !== false;

        try {
            $parentSegments = [
                PathSanitizer::sanitizeSegment($baseFolder),
                PathSanitizer::sanitizeSegment($theatre),
                PathSanitizer::sanitizeSegment($date),
            ];
            $parent = $this->folderService->getOrCreatePath($userId, $parentSegments);

            $leafName = PathSanitizer::sanitizeSegment("$startTime - $presenterName");
            $folder = $this->folderService->createUniqueLeaf($parent, $leafName);
            $result['folderPath'] = $folder->getPath();
        } catch (\Throwable $e) {
            $this->logger->error('File drop batch: folder creation failed', ['app' => 'filedropbatch', 'exception' => $e]);
            $result['message'] = 'Could not create folder: ' . $e->getMessage();
            return $result;
        }

        try {
            $share = $this->shareService->createFileDropShare($folder, $userId, $expiry);
            $result['shareLink'] = $this->shareService->getPublicUrl($share);
        } catch (\Throwable $e) {
            $this->logger->error('File drop batch: share creation failed', ['app' => 'filedropbatch', 'exception' => $e]);
            $result['message'] = 'Folder created, but the file drop link could not be created: ' . $e->getMessage();
            return $result;
        }

        if (!$emailValid) {
            $result['status'] = 'partial';
            $result['message'] = 'Invalid email address - link not emailed, deliver manually.';
            return $result;
        }

        try {
            $this->mailService->sendFileDropLink($presenterEmail, $presenterName, $theatre, $date, $startTime, $result['shareLink']);
            $result['emailSent'] = true;
            $result['status'] = 'success';
        } catch (\Throwable $e) {
            $this->logger->error('File drop batch: sending email failed', ['app' => 'filedropbatch', 'exception' => $e]);
            $result['status'] = 'partial';
            $result['message'] = 'Link created, but the email could not be sent: ' . $e->getMessage();
        }

        return $result;
    }

    private function parseDate(string $value): ?\DateTimeImmutable {
        foreach (self::DATE_FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($parsed !== false) {
                return $parsed;
            }
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? (new \DateTimeImmutable())->setTimestamp($timestamp) : null;
    }

    private function parseTime(string $value): ?\DateTimeImmutable {
        foreach (self::TIME_FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($parsed !== false) {
                return $parsed;
            }
        }
        return null;
    }
}
