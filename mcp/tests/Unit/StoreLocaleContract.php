<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Localized store metadata contract, including the German release-candidate description. */
final class StoreLocaleContract {
	/**
	 * @param array<string, array<string, string>> $elements summary/description indexed by language
	 * @return void assert all supported listing languages and safe nonempty text
	 */
	public static function languages(array $elements): void {
		foreach ($elements as $element => $texts) {
			TestCase::assertSame(['en', 'pt-br', 'es', 'de'], array_keys($texts), $element);
			foreach ($texts as $lang => $text) {
				TestCase::assertNotSame('', $text, "$element ($lang)");
				TestCase::assertStringNotContainsStringIgnoringCase('test release', $text);
				TestCase::assertStringNotContainsStringIgnoringCase('versão de teste', $text);
				TestCase::assertStringNotContainsStringIgnoringCase('dal' . 'comad', $text);
			}
		}
	}

	/**
	 * @param array<string, string> $texts descriptions indexed by language
	 * @return void assert modules, clients, safeguards and declared server support in each description
	 */
	public static function descriptions(array $texts): void {
		$expected = [
			'en' => ['Files', 'Notes', 'Calendar', 'Contacts', 'Tasks', 'Deck', 'Talk', 'never deletes'],
			'pt-br' => ['Arquivos', 'Notas', 'Calendário', 'Contatos', 'Tarefas', 'Deck', 'Talk', 'nunca apaga'],
			'es' => ['Archivos', 'Notas', 'Calendario', 'Contactos', 'Tareas', 'Deck', 'Talk', 'nunca borra'],
			'de' => ['Dateien', 'Notes', 'Kalender', 'Kontakten', 'Aufgaben', 'Deck', 'Talk', 'nicht gelöscht'],
		];
		foreach ($texts as $lang => $text) {
			foreach ([...$expected[$lang], 'Claude', 'ChatGPT', 'Gemini CLI', 'OAuth', 'Nextcloud 32–35'] as $needle) {
				TestCase::assertStringContainsString($needle, $text, "description ($lang)");
			}
		}
	}
}
