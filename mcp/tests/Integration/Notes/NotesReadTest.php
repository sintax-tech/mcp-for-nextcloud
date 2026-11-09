<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Notes;

use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Issue #34: the actual tools/call response after creating, listing and editing a note. */
#[Group('notes')]
final class NotesReadTest extends ExclusionTestCase {
	/** @return iterable<string, array{string, string}> Default/localized folders over both supported login kinds. */
	public static function cases(): iterable {
		foreach (['Notes', 'Notizen'] as $folder) {
			foreach (['basic', 'bearer'] as $kind) {
				yield $folder . '-' . $kind => [$folder, $kind];
			}
		}
	}

	/**
	 * @param string $folder The Notes app's configured folder.
	 * @param string $kind The authentication kind.
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testCreateListReadEditRead(string $folder, string $kind): void {
		$config = Server::get(IConfig::class);
		$settings = $config->getUserValue(self::ALICE, 'notes', 'settings', '');
		$this->later(fn () => $config->setUserValue(self::ALICE, 'notes', 'settings', $settings));
		$config->setUserValue(self::ALICE, 'notes', 'settings', json_encode(['notesPath' => $folder]));
		if (!$this->exists(self::ALICE, $folder)) {
			$this->later(fn () => $this->deleteQuietly(self::ALICE, $folder));
		}
		$this->later(fn () => $this->deleteQuietly(self::ALICE, $folder . '/' . $this->run));
		$client = $this->mcp(self::ALICE, $kind);
		$created = $client->write('notes_create', ['title' => 'QA', 'category' => $this->run, 'content' => "# QA\nMilch und Brot\n"]);
		$this->assertTrue($created->ok(), $created->describe());
		$id = $created->json()['id'];
		$listed = $client->tool('notes_list');
		$this->assertTrue($listed->ok(), $listed->describe());
		$notes = array_column($listed->json(), null, 'id');
		$this->assertArrayHasKey($id, $notes);
		$read = $client->tool('notes_read', ['id' => $id]);
		$this->assertTrue($read->ok(), $read->describe());
		$this->assertSame("# QA\nMilch und Brot\n", $read->json()['content'], $read->describe());
		$path = '/' . $folder . '/' . $this->run . '/QA.md';
		$this->assertSame($path, $read->json()['path']);
		$this->assertSame($notes[$id]['etag'], $read->json()['etag']);
		$this->assertArrayNotHasKey('structuredContent', $read->result());
		// Nextcloud may reuse the ETag for writes within the same second; edit in the next one.
		$now = time();
		while (time() === $now) {
			usleep(50000);
		}
		$edited = $client->write('notes_edit', ['id' => $id, 'etag' => $read->json()['etag'], 'content' => "Updated\n"]);
		$this->assertTrue($edited->ok(), $edited->describe());
		$again = $client->tool('notes_read', ['id' => $id]);
		$this->assertTrue($again->ok(), $again->describe());
		$this->assertSame("Updated\n", $again->json()['content'], $again->describe());
		$this->assertSame($path, $again->json()['path']);
		$this->assertSame($edited->json()['etag'], $again->json()['etag']);
		$this->assertNotSame($read->json()['etag'], $again->json()['etag']);
	}
}
