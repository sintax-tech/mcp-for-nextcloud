<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/** Captures first-refusal diagnostics and privacy-safe warnings for one visibility facade. */
final class VisibilityDiagnostics {
	public const EXPLAINED_VISIBLE = 'visible';
	public const EXPLAINED_TAG_DIRECT = 'tag_direct';
	public const EXPLAINED_TAG_FOLDER = 'tag_folder';
	public const EXPLAINED_TAG_SHARE_ORIGIN = 'tag_share_origin';
	public const EXPLAINED_BACKUP = 'backup_of_excluded';
	public const EXPLAINED_INVALID_CONFIGURATION = 'invalid_configuration';
	/** The check failed (a tag, an ancestor or the owner's tree could not be read): refused, reason unknown. */
	public const EXPLAINED_NOT_EVALUATED = 'not_evaluated';

	/** @var array{reason?: string, folder?: Node}|null First refusal while explanation capture is active. */
	private ?array $explanation = null;
	/** @var bool Invalid configuration is reported once per instance, across cache clears. */
	private bool $invalidLogged = false;

	/**
	 * Bind the optional privacy-safe logger without writing a message.
	 * @param LoggerInterface|null $logger Native logger, or null when logging is unavailable.
	 */
	public function __construct(private ?LoggerInterface $logger) {
	}

	/**
	 * Capture the first refusal from the real virtual decision, clearing caches first and closing capture in finally.
	 * @param Node $node Inspected file or folder.
	 * @param \Closure(): void $clearCache Virtual cache invalidation on the actual facade.
	 * @param \Closure(Node): bool $isVisible Virtual visibility decision on the actual facade.
	 * @return array{reason: string, folder: ?Node} Decision reason and tagged ancestor when applicable.
	 * @throws \Throwable When a virtual decision or invalidation throws; capture is closed after evaluation.
	 */
	public function explain(Node $node, \Closure $clearCache, \Closure $isVisible): array {
		($clearCache)();
		$this->explanation = [];
		try {
			$visible = ($isVisible)($node);
			$why = $this->explanation;
		} finally {
			$this->explanation = null;
		}
		if ($visible) {
			return ['reason' => self::EXPLAINED_VISIBLE, 'folder' => null];
		}
		// A refusal that recorded nothing is never reported as a known reason.
		return ['reason' => $why['reason'] ?? self::EXPLAINED_NOT_EVALUATED, 'folder' => $why['folder'] ?? null];
	}

	/**
	 * Record only the first refusal while an explanation is active.
	 * @param string $reason Public EXPLAINED_* reason code.
	 * @param Node|null $folder Tagged ancestor for the inherited-folder reason.
	 * @return void
	 */
	public function because(string $reason, ?Node $folder = null): void {
		if ($this->explanation !== null && !isset($this->explanation['reason'])) {
			$this->explanation = ['reason' => $reason] + ($folder === null ? [] : ['folder' => $folder]);
		}
	}

	/**
	 * Expose the actual capture storage for the mirror's temporary swap and restoration.
	 * @return array{reason?: string, folder?: Node}|null Reference to the single active diagnostic state.
	 */
	public function &explanation(): ?array {
		return $this->explanation;
	}

	/**
	 * Record an unknown refusal and log each failed check without names, paths or exception details.
	 * @return void
	 */
	public function warnVisibilityFailure(): void {
		$this->because(self::EXPLAINED_NOT_EVALUATED);
		$this->logger?->warning('MCP visibility check failed; access refused', ['app' => 'mcp']);
	}

	/**
	 * Log invalid configuration once per instance, without adding a decision reason or exposing its value.
	 * @return void
	 */
	public function warnInvalidConfiguration(): void {
		if ($this->invalidLogged) {
			return;
		}
		$this->invalidLogged = true;
		$this->logger?->warning('MCP hidden tags configuration is invalid; access refused', ['app' => 'mcp']);
	}
}
