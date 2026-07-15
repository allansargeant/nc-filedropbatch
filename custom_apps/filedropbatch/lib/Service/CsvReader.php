<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

class CsvReader {
    /** Canonical header name => accepted lowercase aliases in the input CSV. */
    private const REQUIRED_HEADERS = [
        'date' => 'Date',
        'theatre' => 'Theatre',
        'start time' => 'Start Time',
        'presenter name' => 'presenter name',
        'presenter email' => 'presenter email',
    ];

    /**
     * Reads the CSV at $path and returns a list of rows, each an associative
     * array keyed by canonical header name plus a "_rowNumber" entry (1-based,
     * counting the header row as row 1).
     *
     * @return array<int, array<string, string>>
     * @throws \RuntimeException if required headers are missing or the file can't be read.
     */
    public function read(string $path): array {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the uploaded CSV file');
        }

        try {
            $headerRow = fgetcsv($handle);
            if ($headerRow === false) {
                throw new \RuntimeException('The CSV file is empty');
            }

            $headerRow = $this->stripBom($headerRow);
            $columnMap = $this->mapColumns($headerRow);

            $rows = [];
            $rowNumber = 1;
            while (($line = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // Skip fully blank lines (common trailing newline in exported CSVs).
                if ($line === [null] || $line === ['']) {
                    continue;
                }

                $row = ['_rowNumber' => (string)$rowNumber];
                foreach ($columnMap as $canonical => $index) {
                    $row[$canonical] = trim((string)($line[$index] ?? ''));
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @param string[] $headerRow */
    private function stripBom(array $headerRow): array {
        if (isset($headerRow[0])) {
            $headerRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRow[0]) ?? $headerRow[0];
        }
        return $headerRow;
    }

    /**
     * @param string[] $headerRow
     * @return array<string, int> canonical header name => column index
     */
    private function mapColumns(array $headerRow): array {
        $normalized = [];
        foreach ($headerRow as $index => $name) {
            $normalized[strtolower(trim((string)$name))] = $index;
        }

        $columnMap = [];
        $missing = [];
        foreach (array_keys(self::REQUIRED_HEADERS) as $canonical) {
            if (!array_key_exists($canonical, $normalized)) {
                $missing[] = self::REQUIRED_HEADERS[$canonical];
                continue;
            }
            $columnMap[$canonical] = $normalized[$canonical];
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'The CSV is missing required column(s): ' . implode(', ', $missing)
            );
        }

        return $columnMap;
    }
}
