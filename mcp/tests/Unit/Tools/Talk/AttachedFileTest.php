<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Modules\Talk\Presentation\ActorNames;
use OCA\Mcp\Modules\Talk\Attachments\AttachedFile;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess;
use OCA\Mcp\Modules\Talk\Locating\Conversation;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;
use OCA\Mcp\Modules\Talk\Attachments\FileSharer;
use OCA\Mcp\Modules\Talk\Presentation\TalkPlanRenderer;
use OCA\Mcp\Modules\Talk\Locating\UserFileResolver;
use OCA\Mcp\Modules\Talk\Writing\WritePreview;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Finding 3 of the RE-01 oracle: talk_attach_file named the file by its storage path, "/alice/files/...", which
 * reveals the account id. The plan, its rendered text and the confirmed result must name the file the way the Files
 * tools do, by its path below the user folder, for the user's own files and for files received through a share.
 */
final class AttachedFileTest extends TestCase {
	/**
	 * @return array<string, array{string, string, string, string|null}> account, storage path of the file, expected
	 *     relative path, and the mount point of the share the file was received through
	 */
	public static function files(): array {
		return [
			'own file' => ['alice', '/alice/files/Relatorios/Q3.pdf', '/Relatorios/Q3.pdf', null],
			'account named like a folder of the path' => ['ana', '/ana/files/Projetos/ana/plano-ana.pdf', '/Projetos/ana/plano-ana.pdf', null],
			'account named like the internal folder' => ['files', '/files/files/files/q.pdf', '/files/q.pdf', null],
			'file received through a share' => ['alice', '/alice/files/Recebidos de bob/plano.pdf', '/Recebidos de bob/plano.pdf', '/alice/files/Recebidos de bob'],
		];
	}

	/**
	 * The plan, the text the user approves and the confirmed result all carry the relative path and never the
	 * storage prefix of the account.
	 *
	 * @param string $userId Account attaching the file
	 * @param string $storagePath Path Nextcloud reports for the node
	 * @param string $expected Path below the user folder
	 * @param string|null $mount Mount point of the received share holding the file, null for the user's own storage
	 */
	#[DataProvider('files')]
	public function testThePlanAndTheResultNameTheFileBelowTheUserFolder(string $userId, string $storagePath, string $expected, ?string $mount): void {
		$tree = new FakeTree($this, '/' . $userId . '/files');
		$tree->mountPath = $mount;
		$tree->addFile($storagePath, 'conteúdo');
		$sharer = $this->sharer($tree->filesystemRoot(), $tree->node($storagePath));
		$preview = new WritePreview($this->createMock(ConversationWriter::class), $sharer,
			$this->createMock(AttachmentAccess::class), new ActorNames($this->createMock(IUserManager::class)));
		$requested = ltrim($expected, '/');

		$plan = $preview->attach($this->conversation(), $userId, $requested, null);
		$text = (string)TalkPlanRenderer::render('talk_attach_file', $plan);
		$result = $sharer->attach($this->conversation(), $userId, $requested);

		$this->assertSame($expected, $plan['draft']['file']['path']);
		$this->assertSame($expected, $result['file']['path']);
		foreach (['plan' => json_encode($plan), 'text' => $text, 'result' => json_encode($result)] as $where => $answer) {
			$this->assertStringNotContainsString('/' . $userId . '/files', str_replace('\/', '/', (string)$answer), $where);
		}
	}

	/** The own file and the received one: the account id does not appear anywhere in what the agent reads. */
	public function testTheAccountIdNeverAppearsForAPathThatDoesNotContainIt(): void {
		foreach (['/alice/files/Relatorios/Q3.pdf', '/alice/files/Recebidos de bob/plano.pdf'] as $storagePath) {
			$tree = new FakeTree($this, '/alice/files');
			$tree->mountPath = dirname($storagePath);
			$tree->addFile($storagePath, 'conteúdo');
			$sharer = $this->sharer($tree->filesystemRoot(), $tree->node($storagePath));

			$result = $sharer->attach($this->conversation(), 'alice', 'any');

			$this->assertStringNotContainsString('alice', (string)json_encode($result), $storagePath);
		}
	}

	/**
	 * A node whose path is the owner's storage path, outside the caller's folder, is named by its file name: neither
	 * the caller nor the owner is revealed.
	 */
	public function testANodeOutsideTheUserFolderIsNamedByItsFileNameOnly(): void {
		$tree = new FakeTree($this, '/alice/files');
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/bob/files/Docs/plano.pdf');
		$file->method('getName')->willReturn('plano.pdf');
		$file->method('getSize')->willReturn(10);

		$described = (new AttachedFile($tree->filesystemRoot()))->describe('alice', $file);

		$this->assertSame(['path' => '/plano.pdf', 'name' => 'plano.pdf', 'size' => 10], $described);
	}

	/** A root that cannot answer for the user still never falls back to the storage path. */
	public function testAFailingUserFolderLookupFallsBackToTheFileName(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willThrowException(new \RuntimeException('no folder'));
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Docs/plano.pdf');
		$file->method('getName')->willReturn('plano.pdf');
		$file->method('getSize')->willReturn(10);

		$this->assertSame('/plano.pdf', (new AttachedFile($root))->describe('alice', $file)['path']);
	}

	/**
	 * The real sharer over the real description, with the file lookup and the share manager replaced.
	 *
	 * @param IRootFolder $root Root whose user folder the path is made relative to
	 * @param File $file The node the lookup resolves
	 * @return FileSharer The sharer under test
	 */
	private function sharer(IRootFolder $root, File $file): FileSharer {
		$resolver = $this->createMock(UserFileResolver::class);
		$resolver->method('resolveShareableFile')->willReturn($file);
		$share = $this->createMock(IShare::class);
		$share->method('setNode')->willReturnSelf();
		$share->method('setShareType')->willReturnSelf();
		$share->method('setSharedWith')->willReturnSelf();
		$share->method('setSharedBy')->willReturnSelf();
		$share->method('setPermissions')->willReturnSelf();
		$share->method('getId')->willReturn('77');
		$shares = $this->createMock(IShareManager::class);
		$shares->method('getSharesBy')->willReturn([]);
		$shares->method('getSharedWith')->willReturn([]);
		$shares->method('newShare')->willReturn($share);
		$shares->method('createShare')->willReturn($share);

		return new FileSharer($shares, $resolver, $this->createMock(ConversationWriter::class), new AttachedFile($root));
	}

	/** @return Conversation A writable conversation whose name says nothing about the account */
	private function conversation(): Conversation {
		return new Conversation(new AttachedFileRoomStub(), new AttachedFileParticipantStub());
	}
}

/** Talk room double with a neutral name. */
final class AttachedFileRoomStub {
	/** @return string The conversation token */
	public function getToken(): string {
		return 'room1';
	}

	/**
	 * @param string $userId Account reading the name
	 * @return string The conversation name
	 */
	public function getDisplayName(string $userId): string {
		return 'Equipe';
	}
}

/** Talk participant double allowed to write. */
final class AttachedFileParticipantStub {
	/** @return int The participant permissions */
	public function getPermissions(): int {
		return 128;
	}
}
