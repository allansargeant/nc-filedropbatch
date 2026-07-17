<?php

declare(strict_types=1);

use OCA\FileDropBatch\Service\CsvReader;
use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase {
    private CsvReader $reader;

    protected function setUp(): void {
        $this->reader = new CsvReader();
    }

    public function testParsesValidRows(): void {
        $lines = [
            ['Date', 'Theatre', 'Start Time', 'presenter name', 'presenter email'],
            ['2026-08-01', 'Globe', '17:00', 'A. Smith', 'a@example.com'],
        ];

        $rows = $this->reader->parseRows($lines);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-08-01', $rows[0]['date']);
        $this->assertSame('Globe', $rows[0]['theatre']);
        $this->assertSame('17:00', $rows[0]['start time']);
        $this->assertSame('A. Smith', $rows[0]['presenter name']);
        $this->assertSame('a@example.com', $rows[0]['presenter email']);
        $this->assertSame('2', $rows[0]['_rowNumber']);
    }

    public function testHeaderMatchingIsCaseInsensitive(): void {
        $lines = [
            ['DATE', 'THEATRE', 'START TIME', 'PRESENTER NAME', 'PRESENTER EMAIL'],
            ['2026-08-01', 'Globe', '17:00', 'A. Smith', 'a@example.com'],
        ];

        $this->assertCount(1, $this->reader->parseRows($lines));
    }

    public function testMissingRequiredHeaderThrows(): void {
        $this->expectException(RuntimeException::class);

        $this->reader->parseRows([
            ['Date', 'Theatre', 'Start Time', 'presenter name'], // missing presenter email
            ['2026-08-01', 'Globe', '17:00', 'A. Smith'],
        ]);
    }

    public function testEmptyInputThrows(): void {
        $this->expectException(RuntimeException::class);
        $this->reader->parseRows([]);
    }

    public function testSkipsBlankLinesWhileStillCountingThemTowardsRowNumber(): void {
        $lines = [
            ['Date', 'Theatre', 'Start Time', 'presenter name', 'presenter email'],
            [null],
            [''],
            [],
            ['2026-08-01', 'Globe', '17:00', 'A. Smith', 'a@example.com'],
        ];

        $rows = $this->reader->parseRows($lines);

        $this->assertCount(1, $rows);
        $this->assertSame('5', $rows[0]['_rowNumber']);
    }

    public function testStripsLeadingUtf8Bom(): void {
        $lines = [
            ["\xEF\xBB\xBFDate", 'Theatre', 'Start Time', 'presenter name', 'presenter email'],
            ['2026-08-01', 'Globe', '17:00', 'A. Smith', 'a@example.com'],
        ];

        $rows = $this->reader->parseRows($lines);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-08-01', $rows[0]['date']);
    }

    public function testMissingTrailingCellsBecomeEmptyStrings(): void {
        $lines = [
            ['Date', 'Theatre', 'Start Time', 'presenter name', 'presenter email'],
            ['2026-08-01', 'Globe'],
        ];

        $rows = $this->reader->parseRows($lines);

        $this->assertSame('', $rows[0]['start time']);
        $this->assertSame('', $rows[0]['presenter name']);
        $this->assertSame('', $rows[0]['presenter email']);
    }
}
