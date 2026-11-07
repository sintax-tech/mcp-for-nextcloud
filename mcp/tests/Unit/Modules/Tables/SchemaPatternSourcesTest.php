<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use PHPUnit\Framework\TestCase;

/** Pins the native meaning before permitting writes to textAllowedPattern. */
final class SchemaPatternSourcesTest extends TestCase {
	/** @return void Link is a CSV, while ordinary text's regex control is commented out, not a proven PCRE API. */
	public function testNativePatternMeaningIsBoundToOfficialSources(): void {
		$base = __DIR__ . '/../../../fixtures/nextcloud-api/tables/';
		$manifest = json_decode(file_get_contents($base . 'schema-api.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach (json_decode(file_get_contents($base . 'schema-patterns.json'), true, 512, JSON_THROW_ON_ERROR) as $pin => $entry) {
			$this->assertSame($manifest['sources'][$pin]['archiveSha256'], $entry['archiveSha256']);
			foreach ($entry['sources'] as $file) $this->assertSame($file['sha256'], hash('sha256', $file['source']));
			$link = $entry['sources']['lib/Service/ColumnTypes/TextLinkBusiness.php']['source'];
			$this->assertStringContainsString("explode(',', $" . "column->getTextAllowedPattern()", $link);
			$form = $entry['sources']['src/shared/components/ncTable/partials/columnTypePartials/forms/TextLineForm.vue']['source'];
			$this->assertMatchesRegularExpression('/<!--\s*<div[^>]*>.*?mutableColumn\.textAllowedPattern.*?--> +/sx', $form);
		}
	}
}
