<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Tables\Presentation\SchemaMessages;
use OCA\Mcp\Modules\Tables\Writing\SchemaViewPreservation;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use PHPUnit\Framework\TestCase;

/** The client sees the specific preservation rule in the account language, with no target data. */
final class Tables240MessagesTest extends TestCase {
	/** @return void English PHP source and every shipped locale explain the specific refusal. */
	public function testCardSourceRefusalIsLocalizedAndExposedToTheClient(): void {
		try {
			Translator::reset();
			$english = "This change would clear the view's card title/background source; keep that column selected.";
			self::assertSame($english, SchemaMessages::cardSourcePreservation());
			foreach (['pt_BR', 'es', 'de'] as $locale) {
				Translator::use(new JsonL10n($locale));
				$translated = SchemaMessages::cardSourcePreservation();
				self::assertNotSame($english, $translated, $locale);
				try {
					SchemaViewPreservation::columnAdded(['viewSettings' => ['cardTitleSource' => 1], 'columnSettings' => []], '2.4.0');
					self::fail('Unsafe card-source change was accepted');
				} catch (ArgumentValidationException $error) {
					self::assertSame(['field' => 'view_ids', 'rule' => $translated], $error->details());
					self::assertStringContainsString($translated, $error->clientMessage());
				}
			}
		} finally {
			Translator::reset();
		}
	}
}
