<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Files\UnifiedDiff;
use PHPUnit\Framework\TestCase;

/** The unified diff returned by files_edit, files_replace and the checkout upload. */
final class UnifiedDiffTest extends TestCase {
    public function testIdenticalContentHasNoDiff(): void {
        $this->assertSame('', UnifiedDiff::between("a\nb", "a\nb"));
    }

    public function testFullReplacementNumbersBothSidesFromOne(): void {
        $this->assertSame("--- antes\n+++ depois\n@@ -1,2 +1,1 @@\n-# Ata\n-olá\n+novo\n",
            UnifiedDiff::between("# Ata\nolá", 'novo'));
    }

    public function testSingleChangedLineKeepsThreeLinesOfContext(): void {
        $diff = UnifiedDiff::between("a\nb\nc\nd\ne\nf\ng\nh", "a\nb\nc\nX\ne\nf\ng\nh");
        $this->assertSame("--- antes\n+++ depois\n@@ -1,7 +1,7 @@\n a\n b\n c\n-d\n+X\n e\n f\n g\n", $diff);
    }

    public function testChangesFarApartBecomeTwoHunks(): void {
        $diff = UnifiedDiff::between("1\n2\n3\n4\n5\n6\n7\n8\n9\n10\n11\n12\n13\n14\n15\n16\n17\n18",
            "X\n2\n3\n4\n5\n6\n7\n8\n9\n10\n11\n12\n13\n14\n15\n16\n17\nY");
        $this->assertSame(2, substr_count($diff, '@@ -'));
        $this->assertStringContainsString('-1', $diff);
        $this->assertStringContainsString('-18', $diff);
    }

    public function testAddedLinesOnly(): void {
        $this->assertSame("--- antes\n+++ depois\n@@ -1,1 +1,2 @@\n a\n+b\n", UnifiedDiff::between('a', "a\nb"));
    }

    public function testCarriageReturnsAreNormalizedBeforeDiffing(): void {
        $this->assertSame('', UnifiedDiff::between("a\r\nb", "a\nb"));
    }

    public function testBomIsPartOfTheFirstLine(): void {
        $diff = UnifiedDiff::between("\u{FEFF}a", "\u{FEFF}b");
        $this->assertStringContainsString("-\u{FEFF}a", $diff);
        $this->assertStringContainsString("+\u{FEFF}b", $diff);
    }

    /** A huge change must not build a huge table: past the bound the diff degrades to a summary. */
    public function testOversizedChangeDegradesToASummary(): void {
        $before = implode("\n", array_map(static fn (int $i) => "linha $i", range(1, 3000)));
        $after = implode("\n", array_map(static fn (int $i) => "outra $i", range(1, 3000)));
        $diff = UnifiedDiff::between($before, $after);
        $this->assertStringContainsString('(3000 linhas)', $diff);
        $this->assertStringEndsWith(UnifiedDiff::TRUNCATED, $diff);
    }

    public function testMoreLinesThanTheBoundDegradesToASummary(): void {
        $before = implode("\n", range(1, UnifiedDiff::MAX_LINES + 1));
        $after = implode("\n", range(1, UnifiedDiff::MAX_LINES + 1)) . "\nultima";
        $diff = UnifiedDiff::between($before, $after);
        $this->assertStringContainsString('linhas)', $diff);
        $this->assertStringEndsWith(UnifiedDiff::TRUNCATED, $diff);
    }

    public function testALocalizedChangeInALargeFileStillDiffsExactly(): void {
        $lines = range(1, UnifiedDiff::MAX_LINES - 1);
        $before = implode("\n", $lines);
        $after = implode("\n", array_map(static fn (int $i) => $i === 9000 ? 'alterado' : (string)$i, $lines));
        $diff = UnifiedDiff::between($before, $after);
        $this->assertStringContainsString('-9000', $diff);
        $this->assertStringContainsString('+alterado', $diff);
    }

    public function testLongDiffIsTruncatedAtTheCharacterLimit(): void {
        $lines = range(1, UnifiedDiff::MAX_LINES + 1);
        $before = implode("\n", $lines);
        $after = implode("\n", array_map(static fn (int $i) => "outra linha $i", $lines));
        $diff = UnifiedDiff::between($before, $after);
        $this->assertLessThanOrEqual(UnifiedDiff::MAX_CHARS + strlen(UnifiedDiff::TRUNCATED) + 2, strlen($diff));
        $this->assertStringContainsString(UnifiedDiff::TRUNCATED, $diff);
    }
}
