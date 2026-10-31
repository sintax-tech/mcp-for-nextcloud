<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Real caller/session/DI and native ACL, including a reference the native API leaks to collaborators. */
#[Group('deck')]
final class McpDeckReadTest extends IntegrationTestCase {
	public function testEveryCurrentConsumerKeepsPrivateReferencesAndFileCountsOut(): void {
		$f = DeckFixture::run(DeckFixture::OWNER, 'seed');
		$f += DeckFixture::run(DeckFixture::OWNER, 'empty-label-board');
		DeckFixture::run(DeckFixture::OWNER, 'manage-source', $f);
		$hidden = DeckFixture::run(DeckFixture::OUTSIDER, 'private-seed');
		DeckFixture::run(DeckFixture::OWNER, 'dependencies', $f + ['hiddenCard' => $hidden['a']]);
		// Native count is positive, but the formatter must omit it even when the File domain cannot authorize it.
		DeckFixture::run(DeckFixture::OWNER, 'share', $f);
		foreach ([DeckFixture::READER, DeckFixture::EDITOR] as $uid) {
			$out = DeckFixture::run($uid, 'mcp-observe', $f);
			self::assertFalse($out['privateReadable']);
			if ((int)getenv('MCP_IT_NC') >= 34) { self::assertContains($f['privateCard'], $out['nativeReferences']); }
			foreach (['read', 'list', 'stack', 'followup'] as $path) {
				self::assertArrayHasKey($path, $out['references']);
			}
			foreach ($out['references'] as $ids) { self::assertNotContains($f['privateCard'], $ids); }
			foreach ($out['tools'] as $tool => $result) {
				self::assertFalse($result['isError'] ?? false, "$uid $tool");
				$this->assertSafePayload($result);
			}
			$labels = json_decode($out['tools']['deck_list_labels']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
			self::assertCount(1, $labels['labels']);
			self::assertSame($f['board'], $labels['labels'][0]['boardId']);
			self::assertFalse($out['emptyBoardLabels']['isError'] ?? false);
			$empty = json_decode($out['emptyBoardLabels']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
			self::assertSame([], $empty['labels']);
			self::assertSame(0, $empty['total']);
			self::assertSame([], json_decode($out['emptyLabels']['content'][0]['text'], true)['labels']);
			self::assertTrue($out['deniedLabels']['isError']);
			self::assertSame($out['deniedLabels'], $out['missingLabels']);
			if (isset($out['preview'])) { $this->assertSafePayload($out['preview']); $this->assertSafePayload($out['write']); }
		}
		DeckFixture::run(DeckFixture::OWNER, 'trash-reference', $f);
		$owner = DeckFixture::run(DeckFixture::OWNER, 'mcp-observe', $f);
		self::assertFalse($owner['privateReadable']);
		foreach ($owner['references'] as $ids) { self::assertNotContains($f['privateCard'], $ids); }
	}

	private function assertSafePayload(array $payload): void {
		$encoded = json_encode($payload, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('dependentCards', $encoded);
		self::assertStringNotContainsString('attachmentCount', $encoded);
		self::assertStringNotContainsString('Private reference', $encoded);
	}
}
