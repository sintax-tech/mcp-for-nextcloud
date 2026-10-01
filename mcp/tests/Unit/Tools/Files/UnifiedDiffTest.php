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
        $this->assertSame(
            "--- antes\n+++ depois\n@@ -1,2 +1,1 @@\n-# Ata\n-olá\n\\ No newline at end of file\n+novo\n\\ No newline at end of file\n",
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
        $this->assertSame(
            "--- antes\n+++ depois\n@@ -1,1 +1,2 @@\n a\n\\ No newline at end of file\n+b\n\\ No newline at end of file\n",
            UnifiedDiff::between('a', "a\nb"));
    }

    public function testCarriageReturnsAreNormalizedBeforeDiffing(): void {
        $this->assertSame('', UnifiedDiff::between("a\r\nb", "a\nb"));
    }

    /** A terminated file used to grow a phantom empty line, which showed up as a "+" with nothing in it. */
    public function testATerminatedFileDoesNotGrowAPhantomLine(): void {
        $this->assertSame("--- antes\n+++ depois\n@@ -1,2 +1,3 @@\n a\n b\n+c\n",
            UnifiedDiff::between("a\nb\n", "a\nb\nc\n"));
        $this->assertStringNotContainsString("+\n", UnifiedDiff::between("a\nb\n", "a\nb\nc\n"));
    }

    /** The terminator is a byte like any other, so the diff says so instead of hiding it. */
    public function testAMissingTrailingNewlineIsMarked(): void {
        $this->assertSame("--- antes\n+++ depois\n@@ -1,1 +1,1 @@\n-a\n\\ No newline at end of file\n+a\n",
            UnifiedDiff::between('a', "a\n"));
        $this->assertSame("--- antes\n+++ depois\n@@ -1,1 +1,1 @@\n-a\n+a\n\\ No newline at end of file\n",
            UnifiedDiff::between("a\n", 'a'));
    }

    public function testTheMarkerLandsOnTheLastLineOfEachSide(): void {
        $this->assertSame(
            "--- antes\n+++ depois\n@@ -1,1 +1,2 @@\n a\n+b\n\\ No newline at end of file\n",
            UnifiedDiff::between("a\n", "a\nb"));
        $this->assertSame(
            "--- antes\n+++ depois\n@@ -1,1 +1,2 @@\n a\n\\ No newline at end of file\n+b\n\\ No newline at end of file\n",
            UnifiedDiff::between('a', "a\nb"));
    }

    public function testFullyTerminatedFilesCarryNoMarker(): void {
        $this->assertStringNotContainsString(UnifiedDiff::NO_NEWLINE, UnifiedDiff::between("a\nb\n", "a\nc\n"));
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
