<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Files;

use DateTime;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\Comments\ICommentsManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use RuntimeException;

/**
 * Comments and system tags of a file, read through the embedded native DAV server as the session's own user: the
 * user's own file answers with its comment and only the visible tag, the read marker stays where it was, and a file
 * of another user is refused exactly like a missing one.
 */
final class MetadataDavTest extends IntegrationTestCase {
	/** @var list<IUser> */
	private array $users = [];
	/** @var list<ISystemTag> */
	private array $tags = [];
	/** @var list<string> commented file IDs */
	private array $commented = [];

	protected function tearDown(): void {
		foreach ($this->commented as $id) {
			Server::get(ICommentsManager::class)->deleteCommentsAtObject('files', $id);
		}
		if ($this->tags !== []) {
			Server::get(ISystemTagManager::class)->deleteTags(array_map(static fn (ISystemTag $tag): string => $tag->getId(), $this->tags));
		}
		foreach ($this->users as $user) {
			$user->delete();
		}
		parent::tearDown();
	}

	public function testOwnFileAnswersAndForeignFileIsRefusedLikeAMissingOne(): void {
		$apps = Server::get(IAppManager::class);
		self::assertTrue($apps->isEnabledForAnyone('comments'), 'The comments app ships enabled in every cell.');
		self::assertTrue($apps->isEnabledForAnyone('systemtags'), 'The systemtags app ships enabled in every cell.');
		$alice = $this->user('mcp-it-dav-a-');
		$bob = $this->user('mcp-it-dav-b-');
		$roots = Server::get(IRootFolder::class);
		$own = (string)$roots->getUserFolder($alice->getUID())->newFile('metadata.txt', 'own')->getId();
		$foreign = (string)$roots->getUserFolder($bob->getUID())->newFile('private.txt', 'foreign')->getId();
		$comments = Server::get(ICommentsManager::class);
		foreach ([$own => 'comment on the own file', $foreign => 'comment on the foreign file'] as $id => $message) {
			$comment = $comments->create('users', $bob->getUID(), 'files', (string)$id);
			$comment->setMessage($message)->setVerb('comment');
			$comments->save($comment);
			$this->commented[] = (string)$id;
		}
		$marker = new DateTime('-1 day');
		$comments->setReadMark('files', $own, $marker, $alice);
		$tags = Server::get(ISystemTagManager::class);
		$suffix = bin2hex(random_bytes(4));
		$this->tags[] = $visible = $tags->createTag('mcp-it-visible-' . $suffix, true, true);
		$this->tags[] = $invisible = $tags->createTag('mcp-it-invisible-' . $suffix, false, false);
		$mapper = Server::get(ISystemTagObjectMapper::class);
		foreach ([$own, $foreign] as $id) {
			$mapper->assignTags($id, 'files', [$visible->getId(), $invisible->getId()]);
		}

		$read = self::actor($alice->getUID(), ['files' => [(int)$own, (int)$foreign, PHP_INT_MAX]]);

		self::assertSame($alice->getUID(), $read['session'], 'the reads leave the session user as it was');
		self::assertSame(207, $read['files'][$own]['comments']['status']);
		self::assertStringContainsString('comment on the own file', $read['files'][$own]['comments']['body']);
		self::assertSame(207, $read['files'][$own]['tags']['status']);
		self::assertStringContainsString('mcp-it-visible-' . $suffix, $read['files'][$own]['tags']['body']);
		self::assertStringNotContainsString('mcp-it-invisible-' . $suffix, $read['files'][$own]['tags']['body']);
		self::assertEquals($marker, $comments->getReadMark('files', $own, $alice), 'reading did not move the read marker');
		foreach ([$foreign, (string)PHP_INT_MAX] as $id) {
			foreach (['comments', 'tags'] as $kind) {
				self::assertSame(['refused' => CommonMessages::notFound()], $read['files'][$id][$kind], $kind . ' of ' . $id);
			}
		}
	}

	/** @param string $prefix disposable user prefix @return IUser a fresh user, removed in tearDown */
	private function user(string $prefix): IUser {
		$user = Server::get(IUserManager::class)->createUser($prefix . bin2hex(random_bytes(4)), bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $user);
		$this->users[] = $user;
		return $user;
	}

	/**
	 * @param string $uid reader
	 * @param array<string,mixed> $input file IDs to read
	 * @return array<string,mixed> what metadata-dav-actor.php printed
	 * @throws RuntimeException when the process fails or answers no JSON
	 */
	private static function actor(string $uid, array $input): array {
		$process = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __DIR__ . '/metadata-dav-actor.php', $uid],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start the metadata actor');
		}
		fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
		fclose($pipes[0]);
		$output = (string)stream_get_contents($pipes[1]);
		$errors = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (($status = proc_close($process)) !== 0) {
			throw new RuntimeException("Metadata actor $uid failed ($status): $errors $output");
		}
		return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	}
}
