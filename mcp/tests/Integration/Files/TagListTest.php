<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Files;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Existing NC34/35 cells: files_list_tags over real HTTP (app password and OAuth) lists only the tag the user may see,
 * answers a file hidden by an MCP exclusion tag exactly like a missing path, refuses a path into another user's
 * folder without naming anything, and leaves every tag relation as it was.
 */
final class TagListTest extends IntegrationTestCase {
	/** @var list<IUser> owned disposable accounts */
	private array $users = [];
	/** @var list<ISystemTag> owned tags */
	private array $tags = [];
	/** Previous MCP global switch and exclusion selection, restored after the test; null when untouched. */
	private ?array $saved = null;

	/** @return void restore the switch and the exclusion selection, then remove owned tags and accounts */
	protected function tearDown(): void {
		if ($this->saved !== null) {
			$config = Server::get(IConfig::class);
			foreach ($this->saved as $key => $value) {
				$value === null ? $config->deleteAppValue('mcp', $key) : $config->setAppValue('mcp', $key, $value);
			}
		}
		if ($this->tags !== []) {
			Server::get(ISystemTagManager::class)->deleteTags(array_map(static fn (ISystemTag $tag): string => $tag->getId(), $this->tags));
		}
		foreach ($this->users as $user) {
			$user->delete();
		}
		parent::tearDown();
	}

	/** @return void the visible tag only, the excluded file refused like a missing one, no relation changed */
	public function testListsOnlyVisibleTagsAndRefusesHiddenForeignAndMissingAlike(): void {
		$alice = $this->user('mcp-it-tags-a-');
		$bob = $this->user('mcp-it-tags-b-');
		$config = Server::get(IConfig::class);
		$this->saved = [];
		foreach ([GrantPolicy::SERVICE_KEY, VisibilityGuard::CONFIG_KEY] as $key) {
			$this->saved[$key] = $config->getAppValue('mcp', $key, "\0") === "\0" ? null : $config->getAppValue('mcp', $key);
		}
		$tags = Server::get(ISystemTagManager::class);
		$suffix = bin2hex(random_bytes(4));
		$this->tags[] = $visible = $tags->createTag('mcp-it-tag-visible-' . $suffix, true, true);
		$this->tags[] = $invisible = $tags->createTag('mcp-it-tag-invisible-' . $suffix, false, false);
		$this->tags[] = $exclusion = $tags->createTag('mcp-it-tag-exclusion-' . $suffix, true, true);
		$config->setAppValue('mcp', VisibilityGuard::CONFIG_KEY, json_encode([$exclusion->getId()], JSON_THROW_ON_ERROR));
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true); $policy->setEligible($alice->getUID(), true); $policy->setConnected($alice->getUID(), true);
		$policy->setGrant($alice->getUID(), 'files', 'read', true);

		$roots = Server::get(IRootFolder::class);
		$own = $roots->getUserFolder($alice->getUID())->newFile('tagged-' . $suffix . '.txt', 'own');
		$hidden = $roots->getUserFolder($alice->getUID())->newFile('excluded-' . $suffix . '.txt', 'hidden');
		$foreign = $roots->getUserFolder($bob->getUID())->newFile('tagged-' . $suffix . '.txt', 'foreign');
		$mapper = Server::get(ISystemTagObjectMapper::class);
		$mapper->assignTags((string)$own->getId(), 'files', [$visible->getId(), $invisible->getId()]);
		$mapper->assignTags((string)$hidden->getId(), 'files', [$visible->getId(), $exclusion->getId()]);
		$mapper->assignTags((string)$foreign->getId(), 'files', [$visible->getId()]);
		$ids = [(string)$own->getId(), (string)$hidden->getId(), (string)$foreign->getId()];
		$before = $mapper->getTagIdsForObjects($ids, 'files');

		foreach ([LoginFactory::basic($alice->getUID()), LoginFactory::bearer($alice->getUID())] as $credential) {
			$client = new McpClient($credential);
			$listed = $client->tool('files_list_tags', ['path' => '/tagged-' . $suffix . '.txt']);
			self::assertTrue($listed->ok(), $listed->describe());
			self::assertSame(['path' => '/tagged-' . $suffix . '.txt', 'tags' => [[
				'id' => $visible->getId(), 'name' => 'mcp-it-tag-visible-' . $suffix,
				'user_visible' => true, 'user_assignable' => true, 'can_assign' => true,
			]]], $listed->json());
			$refusals = [];
			foreach (['/excluded-' . $suffix . '.txt', '/missing-' . $suffix . '.txt', '/../' . $bob->getUID() . '/files/tagged-' . $suffix . '.txt'] as $path) {
				$refused = $client->tool('files_list_tags', ['path' => $path]);
				self::assertFalse($refused->ok(), $path);
				self::assertStringNotContainsString('mcp-it-tag-', $refused->fingerprint());
				self::assertStringNotContainsString('excluded-', $refused->fingerprint());
				$refusals[] = $refused->text();
			}
			self::assertSame(CommonMessages::notFound(), $refusals[0]);
			self::assertSame($refusals[0], $refusals[1], 'a file hidden by an exclusion tag answers like a missing one');
		}
		self::assertSame($before, $mapper->getTagIdsForObjects($ids, 'files'), 'listing changed no tag relation');
	}

	/** @param string $prefix disposable user prefix @return IUser a fresh user, removed in tearDown */
	private function user(string $prefix): IUser {
		$user = Server::get(IUserManager::class)->createUser($prefix . bin2hex(random_bytes(4)), bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $user);
		return $this->users[] = $user;
	}
}
