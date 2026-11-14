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
 * Existing NC34/35 cells: files_set_tag over real HTTP (app password and OAuth). The tool starts denied; with files.tags
 * the plan writes nothing, the confirmed call assigns and then removes one relation through the native DAV
 * collection, and an invisible tag, an exclusion tag (by name and by ID) and an unknown tag get the same refusal with
 * no relation changed. The file's content and every other relation stay as they were.
 */
final class TagSetTest extends IntegrationTestCase {
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

	/** @return void opt-in grant, plan without effect, assign and remove, and one refusal for every unusable tag */
	public function testAssignsAndRemovesOneRelationAndRefusesUnusableTagsAlike(): void {
		$alice = $this->user('mcp-it-settag-');
		$config = Server::get(IConfig::class);
		$this->saved = [];
		foreach ([GrantPolicy::SERVICE_KEY, VisibilityGuard::CONFIG_KEY] as $key) {
			$this->saved[$key] = $config->getAppValue('mcp', $key, "\0") === "\0" ? null : $config->getAppValue('mcp', $key);
		}
		$manager = Server::get(ISystemTagManager::class);
		$suffix = bin2hex(random_bytes(4));
		$this->tags[] = $visible = $manager->createTag('mcp-it-settag-visible-' . $suffix, true, true);
		$this->tags[] = $invisible = $manager->createTag('mcp-it-settag-invisible-' . $suffix, false, false);
		$this->tags[] = $exclusion = $manager->createTag('mcp-it-settag-exclusion-' . $suffix, true, true);
		$this->tags[] = $other = $manager->createTag('mcp-it-settag-other-' . $suffix, true, true);
		$config->setAppValue('mcp', VisibilityGuard::CONFIG_KEY, json_encode([$exclusion->getId()], JSON_THROW_ON_ERROR));
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true); $policy->setEligible($alice->getUID(), true); $policy->setConnected($alice->getUID(), true);
		$policy->setGrant($alice->getUID(), 'files', 'read', true);
		$file = Server::get(IRootFolder::class)->getUserFolder($alice->getUID())->newFile('tag-me-' . $suffix . '.txt', 'content');
		$id = (string)$file->getId();
		$mapper = Server::get(ISystemTagObjectMapper::class);
		$mapper->assignTags($id, 'files', [$other->getId()]);
		$path = '/tag-me-' . $suffix . '.txt';

		foreach ([LoginFactory::basic($alice->getUID()), LoginFactory::bearer($alice->getUID())] as $credential) {
			$client = new McpClient($credential);
			$policy->setGrant($alice->getUID(), 'files', 'tags', false);
			self::assertFalse($client->tool('files_set_tag', ['path' => $path, 'tag' => $visible->getName(), 'assigned' => true])->ok(), 'files.tags starts denied');
			$policy->setGrant($alice->getUID(), 'files', 'tags', true);

			foreach ([true, false] as $assigned) {
				$arguments = ['path' => $path, 'tag' => $visible->getName(), 'assigned' => $assigned];
				$plan = $client->tool('files_set_tag', $arguments);
				self::assertTrue($plan->ok(), $plan->describe());
				self::assertSame($assigned ? 'assign' : 'remove', $plan->structured()['change']);
				self::assertSame(!$assigned, $mapper->haveTag([$id], 'files', $visible->getId()), 'the plan wrote nothing');
				$done = $client->tool('files_set_tag', $arguments + ['confirm' => true, 'plan_state' => $plan->structured()['plan_state']]);
				self::assertTrue($done->ok(), $done->describe());
				self::assertSame([true, $assigned], [$done->json()['changed'], $done->json()['assigned']]);
				self::assertSame($assigned, $mapper->haveTag([$id], 'files', $visible->getId()));
			}

			$refusals = [];
			foreach ([['tag' => $invisible->getName()], ['tag_id' => $invisible->getId()], ['tag' => $exclusion->getName()],
				['tag_id' => $exclusion->getId()], ['tag' => 'mcp-it-settag-missing-' . $suffix]] as $tag) {
				$refused = $client->tool('files_set_tag', ['path' => $path, 'assigned' => true] + $tag);
				self::assertFalse($refused->ok(), json_encode($tag));
				self::assertStringNotContainsString('invisible-', $refused->fingerprint());
				$refusals[] = $refused->text();
			}
			self::assertSame([CommonMessages::notFound()], array_values(array_unique($refusals)));
		}
		self::assertSame([$id => [$other->getId()]], $mapper->getTagIdsForObjects([$id], 'files'), 'only the visible tag came and went');
		self::assertSame('content', $file->getContent());
	}

	/** @param string $prefix disposable user prefix @return IUser a fresh user, removed in tearDown */
	private function user(string $prefix): IUser {
		$user = Server::get(IUserManager::class)->createUser($prefix . bin2hex(random_bytes(4)), bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $user);
		return $this->users[] = $user;
	}
}
