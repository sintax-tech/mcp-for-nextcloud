<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\L10n\Translator;
use OCP\Files\IFilenameValidator;
use OCA\Mcp\Tools\ToolFailure;

/** One canonical backup document: current destination, preserved names and the Files copy preference. */
final class BackupLocation {
	public const KEY = 'backup_settings';
	public const LEGACY = 'MCP backups';

	/** @var array{version:int, folder:string, reserved:list<string>, copies:bool}|null request snapshot */
	private ?array $snapshot = null;

	/** @var bool a failed snapshot remains refused until explicit invalidation */
	private bool $failed = false;

	/** @param BackupSettingsDocument $document fresh reader restricted to this configuration key */
	public function __construct(private BackupSettingsDocument $document) {
	}

	/** @return array{version:int, folder:string, reserved:list<string>, copies:bool} default settings */
	public static function defaults(): array {
		return ['version' => 1, 'folder' => self::LEGACY, 'reserved' => [self::LEGACY], 'copies' => true];
	}

	/**
	 * @param string $name single root-relative name, never normalized from a path
	 * @param IFilenameValidator|null $validator official server name rules for newly submitted destinations
	 * @return void
	 * @throws \InvalidArgumentException for a rejected name without echoing its bytes
	 */
	public static function validate(string $name, ?IFilenameValidator $validator = null): void {
		if ($name === '' || in_array($name, ['.', '..'], true) || strlen($name) > 255
			|| preg_match('/[\p{Cc}\/\\\\]/u', $name) || !mb_check_encoding($name, 'UTF-8')) {
			throw new \InvalidArgumentException('Invalid backup folder name');
		}
		try {
			$validator?->validateFilename($name);
		} catch (\Throwable) {
			throw new \InvalidArgumentException('Invalid backup folder name');
		}
	}

	/**
	 * Reads at every boundary. Failure must never forget the names already protected by the server.
	 * @return array{version:int, folder:string, reserved:list<string>, copies:bool} verified settings
	 * @throws ToolFailure for malformed or unavailable configuration
	 */
	public function current(): array {
		$this->invalidate();
		return $this->snapshot();
	}

	/** @return void discard success/failure metadata after writes, rollbacks or guard cache invalidation */
	public function invalidate(): void {
		$this->snapshot = null;
		$this->failed = false;
	}

	/**
	 * @return array{version:int, folder:string, reserved:list<string>, copies:bool} reusable listing snapshot
	 * @throws ToolFailure if this snapshot could not be read; explicit current() is required at write boundaries
	 */
	public function snapshot(): array {
		if ($this->failed) {
			throw new ToolFailure(Translator::t('Backup settings are unavailable; this MCP operation cannot continue.'));
		}
		if ($this->snapshot !== null) {
			return $this->snapshot;
		}
		try {
			$raw = $this->document->read();
			if ($raw === null) {
				return $this->snapshot = self::defaults();
			}
			$value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
			if (!is_array($value) || count($value) !== 4 || ($value['version'] ?? null) !== 1
				|| !is_string($value['folder'] ?? null) || !is_bool($value['copies'] ?? null)
				|| !is_array($value['reserved'] ?? null) || !array_is_list($value['reserved'])) {
				throw new \UnexpectedValueException();
			}
			$value['folder'] = BackupFolderName::normalize($value['folder']);
			foreach ($value['reserved'] as &$name) {
				if (!is_string($name)) {
					throw new \UnexpectedValueException();
				}
				$name = BackupFolderName::normalize($name);
				self::validate($name);
			}
			unset($name);
			self::validate($value['folder']);
			$value['reserved'] = array_values(array_unique($value['reserved']));
			if (!in_array(self::LEGACY, $value['reserved'], true) || !in_array($value['folder'], $value['reserved'], true)) {
				throw new \UnexpectedValueException();
			}
			return $this->snapshot = $value;
		} catch (\Throwable) {
			$this->failed = true;
			throw new ToolFailure(Translator::t('Backup settings are unavailable; this MCP operation cannot continue.'));
		}
	}

	/**
	 * @param string $path normalized user-relative path
	 * @param self|null $location injected live reader, null for standalone default collaborators
	 * @return bool whether the top-level name is reserved now or was reserved before
	 */
	public static function contains(string $path, ?self $location = null): bool {
		$settings = $location?->current() ?? self::defaults();
		return in_array(BackupFolderName::normalize(explode('/', ltrim($path, '/'), 2)[0]), $settings['reserved'], true);
	}
}
