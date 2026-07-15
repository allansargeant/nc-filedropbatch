<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

final class PathSanitizer {
    private const FORBIDDEN = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];

    /**
     * Makes a single path segment safe to use as a Nextcloud file/folder name.
     * Forbidden characters are replaced (not stripped) so that distinct inputs
     * don't collapse into the same name (e.g. "10:00" and "1000").
     */
    public static function sanitizeSegment(string $value): string {
        $clean = str_replace(self::FORBIDDEN, '-', $value);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean, " .\t\n\r\0\x0B");

        if ($clean === '') {
            return 'untitled';
        }

        return mb_substr($clean, 0, 200);
    }
}
