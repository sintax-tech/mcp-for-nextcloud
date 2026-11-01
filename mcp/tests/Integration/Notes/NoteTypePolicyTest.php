<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Notes;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * Notes file-name policy against a real Nextcloud. The plan and the confirmed write refuse the same names.
 * These tests are written for the combined T15 run. This slice does not execute them.
 */
#[Group('notes')]
final class NoteTypePolicyTest extends ExclusionTestCase {
	private const DENIED = 'This file type cannot be written through MCP. Ask an administrator to review the file type policy.';
	private const UNAVAILABLE = 'The MCP file type policy is unavailable. Ask an administrator to review its settings.';

	private McpClient $alice;

	/** Alice's client. The policy key is removed after every test, including when the test failed. */
	protected function setUp(): void {
		parent::setUp();
		$this->alice = $this->mcp();
	}

	/** Leaves the policy key absent so a later test does not observe this slice's value. */
	protected function tearDown(): void {
		$this->clearPolicy();
		parent::tearDown();
	}

	/** @param list<string> $extensions Canonical allowlist. */
	private function allow(array $extensions): void {
		Server::get(FileWritePolicy::class)->save('allowlist', $extensions, false);
	}

	/** Removes the policy key when this test created one. */
	private function clearPolicy(): void {
		$config = Server::get(IAppConfig::class);
		$config->clearCache(false);
		if ($config->hasKey(FileWritePolicy::APP, FileWritePolicy::KEY, null)) {
			$config->deleteKey(FileWritePolicy::APP, FileWritePolicy::KEY);
		}
		$config->clearCache(false);
	}

	/**
	 * @param string $name File name inside this run's notes category.
	 * @param string $content Stored bytes.
	 * @return File The planted note. It is not created through MCP.
	 */
	private function plant(string $name, string $content): File {
		$folder = $this->ensureFolder(self::ALICE, 'Notes/' . $this->run);
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $this->run));
		$file = $folder->newFile($name, $content);
		$this->assertInstanceOf(File::class, $file);
		return $file;
	}

	/** @return string Current bytes of a planted note. */
	private function stored(string $name): string {
		$node = $this->node(self::ALICE, 'Notes/' . $this->run . '/' . $name);
		$this->assertInstanceOf(File::class, $node);
		return (string)$node->getContent();
	}

	/** The refusal text is the public sentence and does not list extensions. */
	private function assertDenied(ToolReply $reply): void {
		$this->assertTrue($reply->isError(), $reply->describe());
		$this->assertStringContainsString(self::DENIED, $reply->text());
		$this->assertStringNotContainsString('txt', $reply->text());
		$this->assertStringNotContainsString('.md', $reply->text());
	}

	/** Create without md does not leave the category. */
	public function testCreateRefusesBeforeTheCategoryExists(): void {
		$this->allow(['txt']);
		$category = $this->run . '-nova';
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $category));
		$arguments = ['title' => 'Ata', 'content' => 'x', 'category' => $category];
		$this->assertDenied($this->alice->tool('notes_create', $arguments));
		$this->assertDenied($this->alice->write('notes_create', $arguments));
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $category));
	}

	/** Create with md writes the note. */
	public function testCreateAllowsMd(): void {
		$this->allow(['md']);
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $this->run));
		$written = $this->alice->write('notes_create', [
			'title' => 'Permitida',
			'content' => 'ok',
			'category' => $this->run,
		]);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertSame('ok', $this->stored('Permitida.md'));
	}

	/** No policy key leaves create as it was. */
	public function testCreateWithThePolicyOffIsUnchanged(): void {
		$this->clearPolicy();
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $this->run));
		$written = $this->alice->write('notes_create', [
			'title' => 'Livre',
			'content' => 'ok',
			'category' => $this->run,
		]);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertSame('ok', $this->stored('Livre.md'));
	}

	/** A title the sanitizer keeps, with a colon, is refused before the new content is stored. */
	public function testWholeEditRefusesADeniedTitleBeforeWritingContent(): void {
		$this->allow(['md']);
		$note = $this->plant('Ok.md', 'antes');
		$arguments = ['id' => (int)$note->getId(), 'content' => 'depois', 'title' => 'bad:name'];
		$this->assertDenied($this->alice->tool('notes_edit', $arguments));
		$this->assertDenied($this->alice->write('notes_edit', $arguments));
		$this->assertSame('antes', $this->stored('Ok.md'));
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $this->run . '/bad:name.md'));
	}

	/** An append approved under md is refused when confirm reads a policy that no longer allows md. */
	public function testAppendConfirmRefusesAfterThePolicyDropsMd(): void {
		$this->allow(['md']);
		$note = $this->plant('Chamados.md', "# Chamados\n");
		$planReply = $this->alice->tool('notes_edit', ['id' => (int)$note->getId(), 'append' => '- 102']);
		$this->assertTrue($planReply->ok(), $planReply->describe());
		$plan = $planReply->structured();
		$this->allow(['txt']);
		$written = $this->alice->write('notes_edit', [
			'id' => (int)$note->getId(),
			'append' => '- 102',
			'etag' => $plan['etag'],
			'plan_state' => $plan['plan_state'],
		]);
		$this->assertDenied($written);
		$this->assertSame("# Chamados\n", $this->stored('Chamados.md'));
	}

	/** Move without md does not create the target category. */
	public function testMoveRefusesBeforeTheCategoryExists(): void {
		$this->allow(['txt']);
		$note = $this->plant('Ok.md', 'fica');
		$category = $this->run . '-destino';
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $category));
		$arguments = ['id' => (int)$note->getId(), 'category' => $category];
		$this->assertDenied($this->alice->tool('notes_move', $arguments));
		$this->assertDenied($this->alice->write('notes_move', $arguments));
		$this->assertSame('fica', $this->stored('Ok.md'));
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $category));
	}

	/** Delete of an md note still follows the trash rule when md is not allowed. */
	public function testDeleteStillRemovesAnMdNoteWhenMdIsDenied(): void {
		$this->allow(['txt']);
		$note = $this->plant('Ok.md', 'lixo');
		$written = $this->alice->write('notes_delete', ['id' => (int)$note->getId()]);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertTrue($written->json()['deleted'] ?? false);
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $this->run . '/Ok.md'));
	}

	/** A stored value that is not a policy refuses notes_create and is not treated as off. */
	public function testUnreadablePolicyRefusesCreate(): void {
		$config = Server::get(IAppConfig::class);
		$config->setValueString(FileWritePolicy::APP, FileWritePolicy::KEY, 'not-json', false);
		$config->clearCache(false);
		$category = $this->run . '-quebrada';
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $category));
		$reply = $this->alice->write('notes_create', ['title' => 'Quebrada', 'content' => 'x', 'category' => $category]);
		$this->assertTrue($reply->isError(), $reply->describe());
		$this->assertStringContainsString(self::UNAVAILABLE, $reply->text());
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $category));
	}
}
