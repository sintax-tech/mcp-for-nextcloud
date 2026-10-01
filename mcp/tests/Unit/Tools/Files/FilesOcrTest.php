<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\OcrSupport;

/** files_read of a PDF or image without a text layer: a normal answer that says why, and what to do. */
final class FilesOcrTest extends FilesToolsTestCase {
    /** A valid single blank page: parses, but holds no text. */
    private static function blankPdf(): string {
        $objects = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>", "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>"];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }

    private function read(string $path): array {
        $result = $this->tool('files_read', ['path' => $path]);
        return [$result['content'][0]['text'], json_decode($result['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR)];
    }

    public function testPdfWithoutTextWarnsToInstallOcrWhenTheAppIsInactive(): void {
        $this->tree->addFile('/alice/files/scan.pdf', self::blankPdf(), 'application/pdf');
        [$text, $meta] = $this->read('/scan.pdf');
        $this->assertSame(FilesMessages::noTextOcrInactive(), $text);
        $this->assertFalse($meta['text_layer']);
        $this->assertFalse($meta['ocr_active']);
        $this->assertSame($text, $meta['notice']);
        $this->assertSame('/scan.pdf', $meta['path']);
    }

    public function testPdfWithoutTextSaysOcrRunsInTheBackgroundWhenTheAppIsActive(): void {
        $this->ocrActive = true;
        $this->tree->addFile('/alice/files/scan.pdf', self::blankPdf(), 'application/pdf');
        [$text, $meta] = $this->read('/scan.pdf');
        $this->assertSame(FilesMessages::noTextOcrActive(), $text);
        $this->assertTrue($meta['ocr_active']);
        $this->assertFalse($meta['text_layer']);
    }

    public function testImageIsAnAnswerNotAnError(): void {
        $this->tree->addFile('/alice/files/foto.jpg', "\xFF\xD8", 'image/jpeg');
        [, $meta] = $this->read('/foto.jpg');
        $this->assertFalse($meta['text_layer']);
        $this->assertSame('image/jpeg', $meta['mime']);
    }

    public function testPdfWithTextHasNoWarning(): void {
        $this->tree->addFile('/alice/files/relatorio.pdf', (string)file_get_contents(__DIR__ . '/../../../fixtures/sample.pdf'), 'application/pdf');
        [$text, $meta] = $this->read('/relatorio.pdf');
        $this->assertStringContainsString('HELLO_MCP', $text);
        $this->assertArrayNotHasKey('text_layer', $meta);
        $this->assertArrayNotHasKey('notice', $meta);
    }

    public function testDetectionAndGuideNotes(): void {
        $this->assertTrue(OcrSupport::canLackText('a.PDF', ''));
        $this->assertTrue(OcrSupport::canLackText('a', 'image/png'));
        $this->assertFalse(OcrSupport::canLackText('a.svg', 'image/svg+xml'));
        $this->assertFalse(OcrSupport::canLackText('a.docx', 'application/zip'));
        $this->assertStringContainsString('text_layer', implode(' ', $this->module->guideNotes()));
        $this->assertSame(FilesModule::class, $this->module::class);
    }
}
