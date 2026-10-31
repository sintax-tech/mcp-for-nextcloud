<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Files\Deletion\DeleteMessages;
use OCA\Mcp\Tools\Files\Deletion\TrashReceiptMessages;
use PHPUnit\Framework\TestCase;

/** "Try the trash bin first" and the trash receipt read the same way in en, de, es and pt_BR, never as trash-only. */
final class TrashReceiptTranslationTest extends TestCase {
	/** @return void Restore the default translator. */
	protected function tearDown(): void {
		Translator::reset();
	}

	/** @return array<string, array{string, list<string>}> Language and the fragments each text must carry, in order. */
	public static function languages(): array {
		return [
			'en' => ['en', ['Try the trash bin first: Nextcloud tries to move the item to the trash bin and may permanently delete it if that fails',
				'confirmed in your trash bin at the time of the check', 'Only the folder itself was checked', 'limited time', 'MCP does not check the trash bin of another person']],
			'de' => ['de', ['Zuerst den Papierkorb versuchen: Nextcloud versucht, das Element in den Papierkorb zu verschieben, und kann es endgültig löschen',
				'zum Zeitpunkt der Prüfung in Ihrem Papierkorb bestätigt', 'Geprüft wurde nur der Ordner selbst', 'begrenzte Zeit', 'MCP prüft nicht den Papierkorb einer anderen Person']],
			'es' => ['es', ['Intenta la papelera primero: Nextcloud intenta mover el elemento a la papelera y puede eliminarlo de forma permanente',
				'se confirmó en su papelera en el momento de la comprobación', 'Solo se comprobó la carpeta en sí', 'tiempo limitado', 'MCP no consulta la papelera de otra persona']],
			'pt_BR' => ['pt_BR', ['Tenta a lixeira primeiro: o Nextcloud tenta mover o item para a lixeira e pode excluí-lo definitivamente',
				'confirmado na sua lixeira no momento da checagem', 'Só a pasta em si foi verificada', 'tempo limitado', 'O MCP não consulta a lixeira de outra pessoa']],
		];
	}

	/**
	 * @param string $language Account language.
	 * @param list<string> $fragments Expected fragment of risk, observed file, observed folder, time-limit and owner texts.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('languages')]
	public function testTextsAreTranslated(string $language, array $fragments): void {
		Translator::use(new JsonL10n($language));
		$texts = [DeleteMessages::risk(), TrashReceiptMessages::observedFile(), TrashReceiptMessages::observedFolder(),
			TrashReceiptMessages::expiry(), TrashReceiptMessages::ownerTrash()];
		foreach ($texts as $index => $text) {
			self::assertStringContainsString($fragments[$index], $text);
		}
		if ($language !== 'en') {
			self::assertStringNotContainsString('Try the trash bin first', DeleteMessages::risk());
		}
	}

	/** @return void The tool description states the N1 behaviour and the receipt limits in English. */
	public function testToolDescriptionStatesTryTheTrashBinFirst(): void {
		$tool = DeleteMessages::tool();
		self::assertStringStartsWith('Try the trash bin first:', $tool);
		self::assertStringContainsString('Nextcloud tries to move the item to the trash bin and may permanently delete it if that fails.', $tool);
		self::assertStringContainsString('confirmed in your own trash bin at the time of the check', $tool);
		self::assertStringContainsString('destination is reported as not confirmed', $tool);
		self::assertStringNotContainsString('only to the trash', $tool);
	}
}
