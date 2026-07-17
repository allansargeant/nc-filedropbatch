<?php

declare(strict_types=1);

use OCA\FileDropBatch\Service\PathSanitizer;
use PHPUnit\Framework\TestCase;

final class PathSanitizerTest extends TestCase {
    public function testReplacesForbiddenCharacters(): void {
        $this->assertSame('10-00', PathSanitizer::sanitizeSegment('10:00'));
    }

    public function testDistinctInputsDoNotCollide(): void {
        // The whole point of replacing (not stripping) forbidden characters -
        // "10:00" and "1000" must not sanitize to the same folder name.
        $this->assertNotSame(
            PathSanitizer::sanitizeSegment('10:00'),
            PathSanitizer::sanitizeSegment('1000'),
        );
    }

    public function testCollapsesRunsOfWhitespace(): void {
        $this->assertSame('a b', PathSanitizer::sanitizeSegment('a   b'));
    }

    public function testTrimsSurroundingWhitespaceAndDots(): void {
        $this->assertSame('name', PathSanitizer::sanitizeSegment('  name.  '));
    }

    public function testEmptyOrBlankInputBecomesUntitled(): void {
        $this->assertSame('untitled', PathSanitizer::sanitizeSegment(''));
        $this->assertSame('untitled', PathSanitizer::sanitizeSegment('   '));
    }

    public function testCapsLengthAtTwoHundredCharacters(): void {
        $long = str_repeat('a', 500);
        $this->assertSame(200, mb_strlen(PathSanitizer::sanitizeSegment($long)));
    }

    public function testReplacesEveryForbiddenCharacter(): void {
        foreach (['/', '\\', ':', '*', '?', '"', '<', '>', '|'] as $char) {
            $this->assertStringNotContainsString($char, PathSanitizer::sanitizeSegment("a{$char}b"));
        }
    }
}
