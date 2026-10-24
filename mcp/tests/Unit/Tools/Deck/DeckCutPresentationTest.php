<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Tools\Deck\CardFileNodeAccess;
use OCA\Mcp\Tools\Deck\CardLinkMessages;
use OCA\Mcp\Tools\Deck\CardLinkPlan;
use OCA\Mcp\Tools\Deck\CardLinkTool;
use OCA\Mcp\Tools\Deck\DeckCapabilities;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DeckPlanRenderer;
use OCA\Mcp\Tools\Deck\DeckRefusalException;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Presentation of the 1.2.0 Deck cut: guide, descriptions, plans and the published texts.
 */
final class DeckCutPresentationTest extends TestCase {
	/**
	 * Drop a translator installed by another test in this process.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Translator::reset();
	}

	/**
	 * The guide names the proved cut and does not claim a missing dependency API.
	 *
	 * @return void
	 */
	public function testGuideStatesTheCutWithoutClaimingAMissingApi(): void {
		$guide = implode("\n", $this->module($this->capabilities(false))->guideNotes());
		self::assertStringContainsString('Nextcloud 32 through 35', $guide);
		self::assertStringContainsString('1.16.8, 1.17.7, 1.18.5 and 1.19.0', $guide);
		self::assertStringContainsString('1.18.5 and 1.19.0', $guide);
		self::assertStringContainsString('those tools are absent', $guide);
		self::assertStringContainsString('does not revoke the link afterwards', $guide);
		self::assertStringContainsString('deck.edit, files.read and files.share', $guide);
		self::assertStringContainsString('attachmentId is the share id', $guide);
		self::assertStringNotContainsString('not supported', $guide);
		self::assertStringNotContainsString('1.3.0', $guide);
	}

	/**
	 * Dependency tools stay out of the catalogue until the proved native API is present.
	 *
	 * @return void
	 */
	public function testDependencyToolsFollowTheProvedVersions(): void {
		$absent = array_column($this->module($this->capabilities(false))->definitions(), 'name');
		self::assertNotContains('deck_add_card_dependency', $absent);
		self::assertContains('deck_list_labels', $absent);
		self::assertContains('deck_list_card_files', $absent);
		$present = $this->module($this->capabilities(true))->definitions();
		$byName = [];
		foreach ($present as $definition) {
			$byName[$definition['name']] = $definition['description'];
		}
		self::assertStringContainsString('NC34/35', $byName['deck_add_card_dependency']);
		self::assertStringContainsString('assignDependentCard', $byName['deck_read_card_dependencies']);
		self::assertStringContainsString('no hidden count', $byName['deck_remove_card_dependency']);
		self::assertStringContainsString('Nextcloud 32 through 35', $byName['deck_list_labels']);
		self::assertStringContainsString('deck.edit', CardLinkTool::description());
		self::assertStringContainsString('files.read and files.share', CardLinkTool::description());
		self::assertStringContainsString('confirm:true', CardLinkTool::description());
		self::assertStringContainsString('No upload, copy or file deletion.', CardLinkTool::description());
	}

	/**
	 * A link plan shows the audience and escapes a hostile name, for none, one and three names.
	 *
	 * @return void
	 */
	public function testLinkPlanShowsAudienceAndEscapesNames(): void {
		foreach ([0, 1, 3] as $count) {
			$names = [];
			for ($i = 0; $i < $count; $i++) {
				$names[] = ['name' => $i === 0 ? 'Ann <script>' : 'Member ' . $i];
			}
			$text = CardLinkPlan::render([
				'file' => ['name' => 'notes.txt', 'path' => '/notes.txt', 'owner' => 'alice', 'size' => 4],
				'card' => ['title' => 'Source'],
				'recipients' => ['total' => 7, 'names' => $names],
				'reshare' => true,
				'existingShares' => [],
			]);
			self::assertStringContainsString('Current recipients: 7.', $text);
			self::assertStringContainsString('READ=1', $text);
			self::assertStringContainsString('not revoked automatically', $text);
			self::assertStringContainsString('does not attest human consent', $text);
			self::assertStringNotContainsString('<script>', $text);
			if ($count > 0) {
				self::assertStringContainsString('Ann \\<script\\>', $text);
			}
		}
	}

	/**
	 * Refreshed and uncertain outcomes do not deny a verified step or claim human approval.
	 *
	 * @return void
	 */
	public function testUncertainOutcomesDoNotClaimSuccess(): void {
		foreach ([
			CardLinkMessages::uncertain(),
			DeckMessages::labelPlanChanged(),
			DeckMessages::labelsUnconfirmed(),
			DeckMessages::labelStateObserved(),
			DeckMessages::filePlanChanged(),
			DeckMessages::dependencyPlanChanged(),
			DeckMessages::fileUnlinkObserved(),
		] as $text) {
			self::assertStringContainsString('does not prove that the whole request finished', $text);
			self::assertStringContainsString('not a record of human approval', $text);
			self::assertStringNotContainsString('Nothing was confirmed as saved', $text);
		}
		self::assertStringNotContainsString('Check whether', CardLinkMessages::refused());
		self::assertStringContainsString('Guest', CardLinkMessages::refused());
		$dependency = (new DeckPlanRenderer())->render('deck_add_card_dependency', [
			'origin' => ['title' => 'From <'],
			'target' => ['title' => 'To'],
			'before' => false,
			'after' => true,
			'board' => ['shared' => false],
		]);
		self::assertIsString($dependency);
		self::assertStringContainsString('Direction is from this card to the target', $dependency);
		self::assertStringContainsString('no count and no id', $dependency);
		self::assertStringContainsString('From \\<', $dependency);
		$labels = (new DeckPlanRenderer())->render('deck_edit_card_labels', [
			'card' => ['title' => 'Card'],
			'board' => ['name' => 'Board', 'shared' => false],
			'before' => [],
			'after' => [['title' => 'Label <', 'id' => 4]],
		]);
		self::assertIsString($labels);
		self::assertStringContainsString('Label \\<', $labels);
	}

	/**
	 * A folder sentence requires visibility, READ and the expected id on every alias.
	 *
	 * @return void
	 */
	public function testFolderSentenceFollowsReadAndIdentity(): void {
		$named = 'Folders cannot be linked or unlinked here. Choose a file.';
		$generic = 'Card, list or board not found, or no permission.';
		$readable = $this->folderAlias(true, 5);
		$unreadable = $this->folderAlias(false, 5);
		$otherId = $this->folderAlias(true, 9);
		$hidden = $this->folderAlias(true, 5, false);
		$mixedFolder = $this->folderAlias(true, 5);
		$mixedFile = $this->fileAlias();
		self::assertSame($named, $this->resolveAliases([$readable], [spl_object_id($readable) => true]));
		self::assertSame($generic, $this->resolveAliases([$unreadable], [spl_object_id($unreadable) => true]));
		self::assertSame($generic, $this->resolveAliases([$otherId], [spl_object_id($otherId) => true]));
		self::assertSame($generic, $this->resolveAliases([$hidden], [spl_object_id($hidden) => false]));
		self::assertSame($generic, $this->resolveAliases(
			[$mixedFolder, $mixedFile],
			[spl_object_id($mixedFolder) => true, spl_object_id($mixedFile) => true],
		));
	}

	/**
	 * Published English and Portuguese docs keep the link limits and the store version.
	 *
	 * @return void
	 */
	public function testPublishedTextsKeepTheLinkContract(): void {
		$repo = dirname(__DIR__, 5);
		$english = (string)file_get_contents($repo . '/README.md') . (string)file_get_contents($repo . '/mcp/README.md');
		$portuguese = (string)file_get_contents($repo . '/README.pt-BR.md') . (string)file_get_contents($repo . '/mcp/README.pt-BR.md');
		self::assertStringContainsString('checked only when the link is created', $english);
		self::assertStringContainsString('does not revoke the link afterwards', $english);
		self::assertStringContainsString('só quando o vínculo é criado', $portuguese);
		self::assertStringContainsString('não revoga o vínculo depois', $portuguese);
		$info = (string)file_get_contents($repo . '/mcp/appinfo/info.xml');
		self::assertStringContainsString('<version>1.2.1-rc.2</version>', $info);
		self::assertStringContainsString('never deletes', $info);
		self::assertStringContainsString('nunca apaga', $info);
		self::assertStringContainsString('nunca borra', $info);
		self::assertStringContainsString('Nextcloud 32–35', $info);
		foreach (['kein-ki', 'lethal trifecta', 'mail_browse', 'tables_browse', 'prepare_context', 'threat-model.md', 'hardening.md'] as $banned) {
			self::assertStringNotContainsString($banned, $english . $portuguese . $info);
		}
	}

	/**
	 * The three catalogues agree with each other and translate the new warning.
	 *
	 * @return void
	 */
	public function testCataloguesMatchAndTranslateTheWarning(): void {
		$root = dirname(__DIR__, 4) . '/l10n';
		$warning = 'Linking grants read-only access (READ=1) to all current board members shown in this plan.';
		$portuguese = null;
		foreach (['pt_BR', 'es', 'de'] as $locale) {
			$json = json_decode((string)file_get_contents($root . '/' . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR);
			preg_match('/\{.*\}/s', (string)file_get_contents($root . '/' . $locale . '.js'), $bundle);
			$js = json_decode($bundle[0], true, 512, JSON_THROW_ON_ERROR);
			self::assertSame($json['translations'], $js, $locale);
			$english = null;
			$hit = null;
			foreach ($json['translations'] as $key => $value) {
				if (str_starts_with($key, $warning)) {
					$english = $key;
					$hit = $value;
				}
			}
			self::assertIsString($hit, $locale);
			self::assertStringContainsString('READ=1', $hit);
			self::assertNotSame($english, $hit);
			if ($locale === 'pt_BR') {
				$portuguese = array_keys($json['translations']);
			}
			if ($locale === 'es') {
				$spanish = array_keys($json['translations']);
				sort($portuguese);
				sort($spanish);
				self::assertSame($portuguese, $spanish);
			}
			if ($locale === 'de') {
				self::assertSame([], array_values(array_diff(array_keys($json['translations']), $portuguese)));
			}
		}
	}

	/**
	 * Build a module whose dependency probe is the only part under test.
	 *
	 * @param DeckCapabilities $capabilities Native dependency gate.
	 * @return DeckToolModule Module that does not resolve Deck at construction.
	 */
	private function module(DeckCapabilities $capabilities): DeckToolModule {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \LogicException('Deck must not be resolved here.'));
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		return new DeckToolModule(
			$container,
			$apps,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ITimeFactory::class),
			null,
			null,
			null,
			$capabilities,
		);
	}

	/**
	 * A proved Nextcloud 35 installation, or a Nextcloud 33 installation without the API.
	 *
	 * @param bool $present Whether the native dependency API is exposed.
	 * @return DeckCapabilities Gate used by the module under test.
	 */
	private function capabilities(bool $present): DeckCapabilities {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($present ? '1.19.0' : '1.17.7');
		if (method_exists(IAppManager::class, 'isEnabledForAnyone')) {
			$apps->method('isEnabledForAnyone')->willReturn(true);
		}
		$apps->method('isInstalled')->willReturn(true);
		return new DeckCapabilities($apps, $present ? 35 : 33, static fn (string $class, string $member): bool => $present);
	}

	/**
	 * One folder alias with native READ and id. Visibility is decided by the guard.
	 *
	 * @param bool $readable Whether native READ is present.
	 * @param int $id Native node id.
	 * @param bool $mayRead Whether READ may be consulted. A hidden alias must not be read.
	 * @return Folder Alias that never exposes its name.
	 */
	private function folderAlias(bool $readable, int $id, bool $mayRead = true): Folder {
		$node = $this->createMock(Folder::class);
		$node->expects(self::never())->method('getName');
		$node->method('getId')->willReturn($id);
		if ($mayRead) {
			$node->method('isReadable')->willReturn($readable);
		} else {
			$node->expects(self::never())->method('isReadable');
		}
		return $node;
	}

	/**
	 * A file alias mixed with a folder must keep the generic refusal.
	 *
	 * @return File Alias that is not a folder.
	 */
	private function fileAlias(): File {
		$node = $this->createMock(File::class);
		$node->expects(self::never())->method('getName');
		$node->method('isReadable')->willReturn(true);
		$node->method('getId')->willReturn(5);
		return $node;
	}

	/**
	 * Resolve the given aliases for file id 5 and return the public refusal.
	 *
	 * @param list<\OCP\Files\Node> $nodes Aliases returned for that id.
	 * @param array<int, bool> $visible Guard answer keyed by spl_object_id of each alias.
	 * @return string Message shown to the caller.
	 */
	private function resolveAliases(array $nodes, array $visible): string {
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->method('isVisible')->willReturnCallback(static fn ($node): bool => $visible[spl_object_id($node)] ?? false);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn($nodes);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		try {
			(new CardFileNodeAccess($guard, $root))->resolve('alice', 5, false);
			self::fail('The aliases were accepted');
		} catch (DeckRefusalException $e) {
			return $e->getMessage();
		}
	}
}
