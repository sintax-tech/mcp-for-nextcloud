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
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;

/**
 * files_add_comment over the real endpoint, with an app password and with a token of the app's own OAuth service: the
 * tool is absent until files.comments is granted, the plan writes nothing, the confirmed call adds exactly one
 * comment as the caller, moves only the caller's own read marker and notifies the mentioned account; a mention of an
 * unknown account and a file of another account write nothing.
 */
final class AddCommentTest extends IntegrationTestCase {
	/** @var list<IUser> */
	private array $users = [];
	/** @var list<string> commented file IDs */
	private array $commented = [];
	private ?string $service = null;

	/**
	 * Removes the comments, the disposable accounts and restores the MCP service switch.
	 *
	 * @return void
	 */
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

	/**
	 * Through an app password and the OAuth service: denied until granted, a plan that writes nothing, one comment by the caller with only the caller's marker moved and the mention notified; an unknown mention and a foreign file write nothing.
	 *
	 * @return void
	 */
	public function testOneCommentByTheCallerWithItsOwnMarkerAndNotification(): void {
		$author = $this->user('mcp-it-cmw-a-');
		$other = $this->user('mcp-it-cmw-b-');
		$roots = Server::get(IRootFolder::class);
		$own = (string)$roots->getUserFolder($author->getUID())->newFile('to-comment.txt', 'own')->getId();
		$roots->getUserFolder($other->getUID())->newFile('foreign.txt', 'foreign');
		$this->commented = [$own];
		$comments = Server::get(ICommentsManager::class);
		$comments->setReadMark('files', $own, new DateTime('2026-01-01 00:00:00'), $other);
		$otherMarker = $comments->getReadMark('files', $own, $other);
		self::assertInstanceOf(\DateTimeInterface::class, $otherMarker);

		$config = Server::get(IConfig::class);
		$this->service = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '');
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible($author->getUID(), true);
		$policy->setConnected($author->getUID(), true);
		$policy->setGrant($author->getUID(), 'files', 'read', true);

		$logins = ['app password' => LoginFactory::basic($author->getUID()), 'OAuth' => LoginFactory::bearer($author->getUID())];
		$basic = new McpClient($logins['app password']);
		self::assertFalse($basic->tool('files_add_comment', ['path' => '/to-comment.txt', 'text' => 'x'])->ok(), 'files.comments starts denied');
		$policy->setGrant($author->getUID(), 'files', 'comments', true);

		$expected = 0;
		foreach ($logins as $login => $credential) {
			$client = new McpClient($credential);
			$text = 'Reviewed through ' . $login . ', @' . $other->getUID();
			$plan = $client->tool('files_add_comment', ['path' => '/to-comment.txt', 'text' => $text]);
			self::assertTrue($plan->ok(), $login . ': ' . $plan->describe());
			$structured = $plan->structured();
			self::assertSame($text, $structured['text'], $login);
			self::assertSame([$other->getUID()], array_column($structured['notifies'], 'id'), $login);
			self::assertCount($expected, $comments->getForObject('files', $own), $login . ': the plan wrote nothing');
			$done = $client->tool('files_add_comment', ['path' => '/to-comment.txt', 'text' => $text, 'confirm' => true, 'plan_state' => $structured['plan_state']]);
			self::assertTrue($done->ok(), $login . ': ' . $done->describe());
			$expected++;
			$stored = $comments->getForObject('files', $own);
			self::assertCount($expected, $stored, $login);
			self::assertSame([$author->getUID(), $text, 'comment'], [$stored[0]->getActorId(), $stored[0]->getMessage(), $stored[0]->getVerb()], $login);
			self::assertSame($stored[0]->getId(), $done->json()['comment']['id'] ?? null, $login . ': the receipt names the stored comment');
			self::assertNotNull($comments->getReadMark('files', $own, $author), $login . ': the author\'s marker moved');
			self::assertSame($otherMarker->getTimestamp(), $comments->getReadMark('files', $own, $other)?->getTimestamp(), $login . ': nobody else\'s marker moved');
			self::assertSame($expected, $this->mentionNotifications($other->getUID(), $own), $login . ': the mentioned account was notified');

			$unknown = $client->tool('files_add_comment', ['path' => '/to-comment.txt', 'text' => 'Hi @mcp-it-nobody-' . bin2hex(random_bytes(3))]);
			self::assertFalse($unknown->ok(), $login);
			$foreign = $client->tool('files_add_comment', ['path' => '/../' . $other->getUID() . '/files/foreign.txt', 'text' => 'Hi']);
			self::assertFalse($foreign->ok(), $login);
			self::assertStringContainsString(CommonMessages::notFound(), $client->tool('files_add_comment', ['path' => '/missing.txt', 'text' => 'Hi'])->text(), $login);
			self::assertCount($expected, $comments->getForObject('files', $own), $login . ': refusals wrote nothing');
		}
	}

	/**
	 * @param string $uid mentioned account
	 * @param string $fileId commented file
	 * @return int comment mention notifications of that account about that file
	 */
	private function mentionNotifications(string $uid, string $fileId): int {
		$query = Server::get(IDBConnection::class)->getQueryBuilder();
		$query->select($query->func()->count('*'))->from('notifications')
			->where($query->expr()->eq('user', $query->createNamedParameter($uid)))
			->andWhere($query->expr()->eq('app', $query->createNamedParameter('comments')))
			->andWhere($query->expr()->eq('subject', $query->createNamedParameter('mention')))
			->andWhere($query->expr()->like('subject_parameters', $query->createNamedParameter('%' . $fileId . '%')));
		$result = $query->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/** @param string $prefix disposable user prefix @return IUser a fresh user, removed in tearDown */
	private function user(string $prefix): IUser {
		$user = Server::get(IUserManager::class)->createUser($prefix . bin2hex(random_bytes(4)), bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $user);
		$this->users[] = $user;
		return $user;
	}
}
