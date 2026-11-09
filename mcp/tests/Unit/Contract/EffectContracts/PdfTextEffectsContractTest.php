<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <jhonatan@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Tools\BrokenTextSamples;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Modules\Files\Extraction\FileReadLimit;
use OCA\Mcp\Modules\Files\Extraction\TextExtractor;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/** Freezes files_read through the endpoint with the real PDF parser and UTF-8 repair, without ext-zip. */
final class PdfTextEffectsContractTest extends TestCase {
	/** @return void the emoji the parser alone loses comes back in the JSON response, without file writes */
	public function testReadingAPdfWithRealExtraction(): void {
		$pdf = BrokenTextSamples::pdf();
		$raw = (new Parser())->parseContent($pdf)->getText();
		$this->assertNotSame(BrokenTextSamples::PDF_TEXT, $raw, 'the native parser alone loses the emoji: CESU-8 up to PHP 8.3, "?" since 8.4.27 and 8.5.12');
		$tree = new FakeTree($this);
		$tree->addFile('/alice/files/emoji.pdf', $pdf, 'application/pdf');
		$before = FilesEffectState::tree($tree);
		$gate = new EffectsGate($this);
		$wiring = new FilesFlowWiring($this, $gate, $tree);
		// Only native storage is fake. Both the TextExtractor and smalot parser run their real implementations.
		$temp = $this->createMock(ITempManager::class);
		$temp->expects($this->never())->method('getTemporaryFile');
		$wiring->services[TextExtractor::class] = new TextExtractor($temp, new FileReadLimit((new InMemoryConfig())->mock($this)));
		$gate->serve(fn (): array => [$wiring->module()]);
		$args = ['path' => '/emoji.pdf'];
		$out = $gate->call('files_read', $args);
		$t = new EffectTranscript($this, 'files.read-pdf-utf8');
		$t->record('read-pdf', $args, $out, ['sourceSha256' => hash('sha256', $pdf),
			'sourceBytes' => strlen($pdf), 'writes' => $tree->ops, 'opened' => $tree->opened]);
		$t->assertGolden();
		$this->assertSame(BrokenTextSamples::PDF_TEXT, $out['body']->result->content[0]->text);
		$this->assertSame($before, FilesEffectState::tree($tree), 'reading preserves every node and its bytes');
		$this->assertSame(['/alice/files/emoji.pdf'], $tree->opened);
	}
}
