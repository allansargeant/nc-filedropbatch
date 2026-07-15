<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

class CsvWriter {
    private const HEADER = ['Date', 'Theatre', 'Start Time', 'presenter name', 'presenter email', 'File Drop Link', 'Status', 'Notes'];
    private const USERS_HEADER = ['Theatre', 'Username', 'Password', 'Status', 'Notes'];

    /**
     * @param array<int, array<string, string>> $rows each with keys:
     *   date, theatre, startTime, presenterName, presenterEmail, shareLink, status, message
     */
    public function write(array $rows): string {
        return $this->buildCsv(self::HEADER, array_map(
            fn (array $row) => [
                $row['date'],
                $row['theatre'],
                $row['startTime'],
                $row['presenterName'],
                $row['presenterEmail'],
                $row['shareLink'],
                $row['status'],
                $row['message'],
            ],
            $rows
        ));
    }

    /**
     * @param array<int, array<string, string>> $rows each with keys:
     *   theatre, username, password, status, message
     */
    public function writeUsers(array $rows): string {
        return $this->buildCsv(self::USERS_HEADER, array_map(
            fn (array $row) => [
                $row['theatre'],
                $row['username'],
                $row['password'],
                $row['status'],
                $row['message'],
            ],
            $rows
        ));
    }

    /**
     * @param string[] $header
     * @param array<int, array<int, string>> $rows
     */
    private function buildCsv(array $header, array $rows): string {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary buffer to write a CSV');
        }

        try {
            fputcsv($handle, $header);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            rewind($handle);
            return stream_get_contents($handle) ?: '';
        } finally {
            fclose($handle);
        }
    }
}
