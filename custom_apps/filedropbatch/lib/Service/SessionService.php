<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCA\FileDropBatch\Db\Session;
use Psr\Log\LoggerInterface;

/**
 * The "create/edit/close one session" logic shared by CSV-row processing
 * (BatchProcessorService) and manual session management (SessionController) -
 * so folder/share/email creation, and field validation, only exist once.
 */
class SessionService {
    private const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd M Y', 'j M Y'];
    private const TIME_FORMATS = ['H:i', 'H:i:s', 'g:ia', 'g:i a', 'g:i A', 'g.ia'];

    public function __construct(
        private FolderService $folderService,
        private ShareService $shareService,
        private MailService $mailService,
        private LoggerInterface $logger,
    ) {
    }

    /** Returns a human-readable error message, or null if the fields are valid. */
    /**
     * Returns null if the row is usable, or a human-readable reason if not.
     *
     * The date parser tries an ordered list of formats and falls back to
     * strtotime(). d/m/Y IS TRIED BEFORE m/d/Y, so an ambiguous value like
     * "03/04/2026" is read as 3 April, not 4 March, and there is no setting
     * for it - a US-format schedule silently lands on the wrong day whenever
     * the day is 12 or lower. The strtotime() fallback is also very
     * permissive and will accept things a spreadsheet never meant.
     *
     * Note the parsed date is used for VALIDATION ONLY. The folder is named
     * from the original string, so "2026-08-01" and "1 Aug 2026" both pass
     * and produce two different folders for the same day.
     */
    public function validateFields(string $date, string $theatre, string $startTime, string $presenterName): ?string {
        foreach (['date' => $date, 'theatre' => $theatre, 'start time' => $startTime, 'presenter name' => $presenterName] as $field => $value) {
            if ($value === '') {
                return "Missing required field: $field";
            }
        }

        if ($this->parseDate($date) === null) {
            return "Unrecognized date format: \"$date\"";
        }
        if ($this->parseTime($startTime) === null) {
            return "Unrecognized time format: \"$startTime\"";
        }

        return null;
    }

    /**
     * Creates the folder, file-drop share, and (if the email address looks
     * valid) sends the link - the same sequence for a CSV row or a manually
     * added session. Caller must have already run validateFields().
     *
     * The returned `status` is the whole point of this method and the three
     * values mean operationally different things:
     *
     *   'success' - folder, link and email all done. Nothing left to do.
     *   'partial' - FOLDER AND LINK EXIST, EMAIL WAS NOT SENT. The presenter
     *               has a working upload link and no idea it exists, so
     *               somebody must deliver it by hand. Two causes, told apart
     *               only by `message`: the address failed
     *               FILTER_VALIDATE_EMAIL, or the mailer threw.
     *   'error'   - no usable link. Note the folder may still have been
     *               created (share creation failing after folder creation
     *               yields 'error' with a message saying so).
     *
     * Every step after validation is caught and logged rather than thrown, so
     * ONE BAD ROW NEVER ABORTS A BATCH. Keep that: a 90-row run that dies on
     * row 4 is worse than one reporting four errors.
     *
     * This method sends real email to real people, with no preview and no
     * undo, and creates a public link anyone with the URL can upload to.
     *
     * @return array{status: string, message: string, folderPath: string, shareLink: string, shareId: string, emailSent: bool}
     */
    public function createSession(
        string $userId,
        string $baseFolder,
        string $theatre,
        string $date,
        string $startTime,
        string $presenterName,
        string $presenterEmail,
        \DateTimeInterface $expiry,
    ): array {
        $result = [
            'status' => 'error',
            'message' => '',
            'folderPath' => '',
            'shareLink' => '',
            'shareId' => '',
            'emailSent' => false,
        ];

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
            $result['shareId'] = $share->getFullId();
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

    /**
     * Moves the session's folder to reflect new theatre/date/start-time/presenter
     * name, updating and returning the entity's cached fields. The email address
     * and the share/link itself are untouched by an edit - Nextcloud shares
     * reference a node by file id, not path, so the link keeps working across
     * the move, and the presenter isn't re-emailed just for a metadata correction.
     */
    public function renameSession(Session $session, string $newTheatre, string $newDate, string $newStartTime, string $newPresenterName): Session {
        $userId = $session->getUserId();
        $node = $this->folderService->getNodeByPath($session->getFolderPath());

        $newParent = $this->folderService->getOrCreatePath($userId, [
            PathSanitizer::sanitizeSegment($session->getBaseFolder()),
            PathSanitizer::sanitizeSegment($newTheatre),
            PathSanitizer::sanitizeSegment($newDate),
        ]);
        $newLeafName = PathSanitizer::sanitizeSegment("$newStartTime - $newPresenterName");

        $moved = $this->folderService->moveToUniqueLeaf($node, $newParent, $newLeafName);

        $session->setTheatre($newTheatre);
        $session->setDate($newDate);
        $session->setStartTime($newStartTime);
        $session->setPresenterName($newPresenterName);
        $session->setFolderPath($moved->getPath());

        return $session;
    }

    /** Revokes the session's file-drop share outright. A no-op if already gone. */
    public function closeSession(Session $session): void {
        $this->shareService->revokeShare($session->getShareId(), $session->getUserId());
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
