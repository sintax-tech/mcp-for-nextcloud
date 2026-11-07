<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

use OCA\Mcp\Platform\Backups\{BackupFolderMatcher, BackupLocation};
use OCA\Mcp\Platform\FileAccess\{CommonMessages, WriteVisibility};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

/**
 * Single exclusion facade for callers, retaining virtual dispatch for the Files scope and Guests gate.
 *
 * Sensitive nodes, their descendants and mirrors remain invisible as not found. Focused request-local parts
 * retain the original caches and evaluation order; only this facade is composed by the application container.
 */
class VisibilityGuard implements WriteVisibility, NodeVisibility, HiddenTagConfiguration, WritePathAccess {
	public const CONFIG_KEY = HiddenTagStore::CONFIG_KEY;

	/** Reasons shown only by the administrator inspector, never by tool refusals. */
	public const EXPLAINED_VISIBLE = VisibilityDiagnostics::EXPLAINED_VISIBLE;
	public const EXPLAINED_TAG_DIRECT = VisibilityDiagnostics::EXPLAINED_TAG_DIRECT;
	public const EXPLAINED_TAG_FOLDER = VisibilityDiagnostics::EXPLAINED_TAG_FOLDER;
	public const EXPLAINED_TAG_SHARE_ORIGIN = VisibilityDiagnostics::EXPLAINED_TAG_SHARE_ORIGIN;
	public const EXPLAINED_BACKUP = VisibilityDiagnostics::EXPLAINED_BACKUP;
	public const EXPLAINED_INVALID_CONFIGURATION = VisibilityDiagnostics::EXPLAINED_INVALID_CONFIGURATION;
	public const EXPLAINED_NOT_EVALUATED = VisibilityDiagnostics::EXPLAINED_NOT_EVALUATED;

	/** @var HiddenTagStore Persistent selection and request-local read state. */
	private HiddenTagStore $tags;
	/** @var VisibilityDiagnostics Shared first-refusal capture and warning suppression. */
	private VisibilityDiagnostics $diagnostics;
	/** @var AncestorChain Mounted tag inheritance and boundary parent resolution. */
	private AncestorChain $ancestors;
	/** @var LocalShareVisibility Canonical owner exclusions and mounted share classification. */
	private LocalShareVisibility $shares;
	/** @var WritePathPolicy Destination preflight and inherited backup tag propagation. */
	private WritePathPolicy $writes;
	/** @var BackupFolderMatcher|null Lazy request-local backup location metadata. */
	private ?BackupFolderMatcher $backupFolderMatcher = null;

	/**
	 * Bind the original native services and create parts before registering virtual cache invalidation.
	 * No part reads configuration, tags, nodes or backup location during construction.
	 * @param IConfig $config Native app configuration.
	 * @param ISystemTagObjectMapper $tagMapper Native tag lookup and assignment service.
	 * @param IRootFolder|null $rootFolder Native root for mount and owner metadata resolution.
	 * @param ISystemTagManager|null $tagManager Optional system-tag selection validator.
	 * @param LoggerInterface|null $logger Optional privacy-safe logger.
	 * @param \OCP\EventDispatcher\IEventDispatcher|null $events Native mutation events.
	 * @param BackupLocation|null $backupLocation Configured current and historical backup metadata.
	 */
	public function __construct(
		IConfig $config,
		ISystemTagObjectMapper $tagMapper,
		private ?IRootFolder $rootFolder = null,
		?ISystemTagManager $tagManager = null,
		?LoggerInterface $logger = null,
		?\OCP\EventDispatcher\IEventDispatcher $events = null,
		private ?BackupLocation $backupLocation = null,
	) {
		$this->tags = new HiddenTagStore($config, $tagManager);
		$this->diagnostics = new VisibilityDiagnostics($logger);
		$this->ancestors = new AncestorChain($rootFolder, $tagMapper, $this, $this->diagnostics, $this->isHiddenBackup(...));
		$this->shares = new LocalShareVisibility($rootFolder, $tagMapper, $this, $this->diagnostics);
		$this->writes = new WritePathPolicy($this, $tagMapper, $this->diagnostics);
		// Invalidate this very instance after OCP mutations outside the MCP tools,
		// too. Run after ordinary privacy hooks; tools also clear in finally.
		$invalidate = fn () => $this->clearCache();
		foreach ([
			\OCP\Files\Events\Node\NodeRenamedEvent::class,
			\OCP\Files\Events\Node\NodeCreatedEvent::class,
			\OCP\Files\Events\Node\NodeCopiedEvent::class,
			\OCP\Files\Events\Node\NodeDeletedEvent::class,
			\OCP\Files\Events\Node\NodeWrittenEvent::class,
			\OCP\SystemTag\MapperEvent::EVENT_ASSIGN,
			\OCP\SystemTag\MapperEvent::EVENT_UNASSIGN,
		] as $event) {
			$events?->addListener($event, $invalidate, PHP_INT_MIN);
		}
	}

	/** {@inheritDoc} */
	public function isVisible(Node $node): bool {
		try {
			return $this->checkVisibility($node);
		} catch (\Throwable) {
			$this->diagnostics->warnVisibilityFailure();
			return false;
		}
	}

	/** {@inheritDoc} */
	public function explain(Node $node): array {
		return $this->diagnostics->explain($node, $this->clearCache(...), $this->isVisible(...));
	}

	/**
	 * Coordinate configuration, empty selection, local-share origin and mounted inheritance in their original order.
	 * @param Node $node Requested file or folder.
	 * @return bool Whether the complete exclusion policy permits the node.
	 * @throws \Throwable Unexpected metadata or tag failures, refused by isVisible().
	 */
	private function checkVisibility(Node $node): bool {
		if (!$this->isConfigurationValid()) {
			$this->diagnostics->warnInvalidConfiguration();
			$this->diagnostics->because(self::EXPLAINED_INVALID_CONFIGURATION);
			return false;
		}
		$hiddenTags = $this->getHiddenTagIds();
		if ($hiddenTags === []) {
			return true;
		}

		// Only local share mounts need the owner's tree: ordinary mounts may
		// have no local owner. Detect shares before using the mounted-path cache.
		if (!$this->shares->isVisibleThroughLocalShare($node, $hiddenTags)) {
			return false;
		}

		return $this->ancestors->isVisible($node, $hiddenTags);
	}

	/** {@inheritDoc} */
	public function parentOf(Node $node): Node {
		return $this->ancestors->parentOf($node);
	}

	/** {@inheritDoc} */
	public function assertVisible(Node $node): void {
		if (!$this->isVisible($node)) {
			throw new ToolFailure(CommonMessages::notFound());
		}
	}

	/** {@inheritDoc} */
	public function isTraversable(Node $node): bool {
		return $this->isVisible($node);
	}

	/** {@inheritDoc} */
	public function assertTraversable(Node $node): void {
		if (!$this->isTraversable($node)) {
			throw new ToolFailure(CommonMessages::notFound());
		}
	}

	/** {@inheritDoc} */
	public function filter(iterable $nodes): array {
		$out = [];
		foreach ($nodes as $node) {
			if ($node instanceof Node && $this->isVisible($node)) {
				$out[] = $node;
			}
		}
		return $out;
	}

	/** {@inheritDoc} */
	public function isPathAllowedForWrite(Folder $root, string $path): bool {
		return $this->writes->isPathAllowedForWrite($root, $path);
	}

	/** {@inheritDoc} */
	public function copyHiddenTags(Node $source, Node $destination): void {
		$this->writes->copyHiddenTags($source, $destination);
	}

	/** {@inheritDoc} */
	public function getHiddenTagIds(): array {
		return $this->tags->getHiddenTagIds();
	}

	/** {@inheritDoc} */
	public function isConfigurationValid(): bool {
		$this->getHiddenTagIds();
		return $this->tags->isConfigurationValid();
	}

	/** {@inheritDoc} */
	public function setHiddenTagIds(array $tagIds): void {
		$this->tags->setHiddenTagIds($tagIds, $this->getHiddenTagIds(...));
		$this->clearNodeCaches();
	}

	/** {@inheritDoc} */
	public function clearCache(): void {
		$this->tags->clearCache();
		$this->clearNodeCaches();
	}

	/**
	 * Clear node and backup metadata after a save or explicit invalidation, retaining the selection and diagnostics.
	 * @return void
	 */
	private function clearNodeCaches(): void {
		$this->ancestors->clearCache();
		$this->shares->clearCache();
		$this->backupFolderMatcher?->clearCache();
		$this->backupLocation?->invalidate();
	}

	/**
	 * Evaluate a backup original through this actual facade while sharing the real explanation storage by reference.
	 * @param Node $node Possible backup mirror.
	 * @return bool Whether the original is hidden or cannot be evaluated.
	 */
	private function isHiddenBackup(Node $node): bool {
		$explanation =& $this->diagnostics->explanation();
		return (new BackupMirrorVisibility($this, $this->backupFolderMatcher ??= new BackupFolderMatcher($this->rootFolder, location: $this->backupLocation), $this->diagnostics->warnVisibilityFailure(...)))->isHidden($node, $explanation);
	}
}
