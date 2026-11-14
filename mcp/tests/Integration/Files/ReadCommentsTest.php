<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Files;

use DateTime;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Comments\ICommentsManager;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;

/**
 * files_read_comments over the real endpoint, with an app password and with a token of the app's own OAuth service:
 * the reader's file answers its comments page by page, newest first, the reader's read marker stays exactly as stored,
 * and a file of another account answers like a missing one.
 */
final class ReadCommentsTest extends IntegrationTestCase {
	/** @var list<IUser> */
	private array $users = [];
	/** @var list<string> commented file IDs */
	private array $commented = [];
	private ?string $service = null;

	protected function tearDown(): void {
		foreach ($this->commented as $id) {
			Server::get(ICommentsManager::class)->deleteCommentsAtObject('files', $id);
		}
		foreach ($this->users as $user) {
			$user->delete();
		}
		if ($this->service !== null) {
			$config = Server::get(IConfig::class);
			$this->service === '' ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->service);
		}
		parent::tearDown();
	}

	public function testPagesReadWithoutMovingTheMarkerAndForeignFilesAreRefused(): void {
		$reader = $this->user('mcp-it-cm-a-');
		$other = $this->user('mcp-it-cm-b-');
		$roots = Server::get(IRootFolder::class);
		$own = (string)$roots->getUserFolder($reader->getUID())->newFile('commented.txt', 'own')->getId();
		$foreign = (string)$roots->getUserFolder($other->getUID())->newFile('foreign.txt', 'foreign')->getId();
		$roots->getUserFolder($reader->getUID())->newFile('quiet.txt', 'no comments');
		$comments = Server::get(ICommentsManager::class);
		foreach (['first comment', 'second comment', 'third comment'] as $i => $message) {
			$comment = $comments->create('users', $other->getUID(), 'files', $own);
			$comment->setMessage($message)->setVerb('comment')->setCreationDateTime(new DateTime('2026-10-0' . ($i + 1) . ' 10:00:00'));
			$comments->save($comment);
		}
		$comments->save($comments->create('users', $other->getUID(), 'files', $foreign)->setMessage('foreign comment')->setVerb('comment'));
		$this->commented = [$own, $foreign];
		$comments->setReadMark('files', $own, new DateTime('2026-10-02 12:00:00'), $reader);
		// The marker as stored (whole seconds), read back before any MCP call.
		$marker = $comments->getReadMark('files', $own, $reader);
		self::assertInstanceOf(\DateTimeInterface::class, $marker);

		$config = Server::get(IConfig::class);
		$this->service = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '');
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible($reader->getUID(), true);
		$policy->setConnected($reader->getUID(), true);
		$policy->setGrant($reader->getUID(), 'files', 'read', true);

		foreach (['app password' => LoginFactory::basic($reader->getUID()), 'OAuth' => LoginFactory::bearer($reader->getUID())] as $login => $credential) {
			$client = new McpClient($credential);
			$first = $client->tool('files_read_comments', ['path' => '/commented.txt', 'limit' => 2]);
			self::assertTrue($first->ok(), $login . ': ' . $first->describe());
			$page = $first->json();
			self::assertSame(['third comment', 'second comment'], array_column($page['comments'], 'text'), $login);
			self::assertSame([true, false], array_column($page['comments'], 'unread'), $login);
			self::assertSame($other->getUID(), $page['comments'][0]['author']['id'], $login);
			self::assertSame(2, $page['next_offset'], $login);
			$second = $client->tool('files_read_comments', ['path' => '/commented.txt', 'limit' => 2, 'offset' => 2])->json();
			self::assertSame(['first comment'], array_column($second['comments'], 'text'), $login);
			self::assertNull($second['next_offset'], $login);
			self::assertSame([], $client->tool('files_read_comments', ['path' => '/quiet.txt'])->json()['comments'], $login);
			$after = $comments->getReadMark('files', $own, $reader);
			self::assertInstanceOf(\DateTimeInterface::class, $after);
			self::assertSame($marker->getTimestamp(), $after->getTimestamp(), $login . ': reading did not move the read marker');
			foreach (['/missing.txt', '/../' . $other->getUID() . '/files/foreign.txt'] as $path) {
				$refused = $client->tool('files_read_comments', ['path' => $path]);
				self::assertFalse($refused->ok(), $login . ' ' . $path);
				self::assertStringNotContainsString('foreign comment', $refused->raw, $login);
			}
			self::assertStringContainsString(CommonMessages::notFound(), $client->tool('files_read_comments', ['path' => '/missing.txt'])->text(), $login);
		}
	}

	/** @param string $prefix disposable user prefix @return IUser a fresh user, removed in tearDown */
	private function user(string $prefix): IUser {
		$user = Server::get(IUserManager::class)->createUser($prefix . bin2hex(random_bytes(4)), bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $user);
		$this->users[] = $user;
		return $user;
	}
}
