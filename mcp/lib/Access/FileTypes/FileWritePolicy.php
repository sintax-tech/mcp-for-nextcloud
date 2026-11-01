<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\FileTypes;

use InvalidArgumentException;
use OCA\Mcp\Access\FileTypes\FileTypeMessages;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Exceptions\AppConfigTypeConflictException;

/** Reads the current global MCP file-name policy and saves one canonical, versioned value. */
final class FileWritePolicy {
	public const APP = 'mcp';
	public const KEY = 'file_write_policy';
	public const MAX_EXTENSIONS = 128;

	/**
	 * @param IAppConfig $config Official Nextcloud app configuration, with no process cache here.
	 * @param IDBConnection $db The same official connection used by app configuration.
	 */
	public function __construct(private IAppConfig $config, private IDBConnection $db) {
	}

	/** @return array{version:int, mode:string, extensions:list<string>, allowExtensionless:bool} Factory policy. */
	public static function defaults(): array {
		return ['version' => 1, 'mode' => 'off', 'extensions' => [], 'allowExtensionless' => false];
	}

	/**
	 * Read afresh at each authorization boundary. A present empty/lazy/wrongly typed key is not absence.
	 *
	 * @return array{version:int, mode:string, extensions:list<string>, allowExtensionless:bool} Valid policy.
	 * @throws FileWritePolicyUnavailable If the configuration cannot be read or validated.
	 */
	public function current(): array {
		try {
			$this->config->clearCache(false);
			if (!$this->config->hasKey(self::APP, self::KEY, null)) {
				return self::defaults();
			}
			$value = json_decode($this->config->getValueString(self::APP, self::KEY, '', false), true, 16, JSON_THROW_ON_ERROR);
			if (!is_array($value) || count($value) !== 4 || ($value['version'] ?? null) !== 1
				|| !isset($value['mode'], $value['extensions'], $value['allowExtensionless'])
				|| !is_string($value['mode']) || !is_array($value['extensions']) || !array_is_list($value['extensions'])
				|| !is_bool($value['allowExtensionless'])) {
				throw new InvalidArgumentException();
			}
			$normalized = $this->normalize($value['mode'], $value['extensions'], $value['allowExtensionless']);
			if ($normalized['extensions'] !== $value['extensions']) {
				throw new InvalidArgumentException();
			}
			return $normalized;
		} catch (\Throwable) {
			// Includes AppConfigTypeConflictException, malformed JSON and an unavailable backend.
			throw new FileWritePolicyUnavailable();
		}
	}

	/**
	 * Save a complete value through the official API. Validate before any storage change.
	 *
	 * @param string $mode off or allowlist.
	 * @param string|array<mixed> $extensions Comma/newline-separated text or a list of extension tokens.
	 * @param bool $allowExtensionless Whether a name without an extension is allowed in allowlist mode.
	 * @return array{version:int, mode:string, extensions:list<string>, allowExtensionless:bool} Saved canonical policy.
	 * @throws InvalidArgumentException For invalid submitted settings, without echoing them.
	 * @throws FileWritePolicyUnavailable If saving or reading back fails.
	 */
	public function save(string $mode, string|array $extensions, bool $allowExtensionless): array {
		$value = $this->normalize($mode, $extensions, $allowExtensionless);
		try {
			// The API's false return means unchanged, not failure; read-back verifies either case.
			$json = json_encode($value, JSON_THROW_ON_ERROR);
			try {
				$this->config->setValueString(self::APP, self::KEY, $json, false);
			} catch (AppConfigTypeConflictException) {
				$this->recoverStringType($json);
			}
			if ($this->current() !== $value) {
				throw new FileWritePolicyUnavailable();
			}
		} catch (\Throwable) {
			throw new FileWritePolicyUnavailable();
		}
		return $value;
	}

	/**
	 * Replace a wrongly typed key atomically, using only public app-config/database APIs.
	 * The transaction belongs to this repair alone; never commit or roll back a caller's transaction.
	 *
	 * @param string $json Already validated canonical policy.
	 * @return void
	 * @throws FileWritePolicyUnavailable On any repair/verification/transaction failure.
	 */
	private function recoverStringType(string $json): void {
		$owned = false;
		try {
			if ($this->db->inTransaction()) {
				throw new FileWritePolicyUnavailable();
			}
			$owned = true;
			$this->db->beginTransaction();
			$this->config->deleteKey(self::APP, self::KEY);
			$this->config->setValueString(self::APP, self::KEY, $json, false);
			if ($this->config->getValueString(self::APP, self::KEY, '', false) !== $json) {
				throw new FileWritePolicyUnavailable();
			}
			$this->db->commit();
			$owned = false;
		} catch (\Throwable) {
			try {
				if ($owned && $this->db->inTransaction()) {
					$this->db->rollBack();
				}
			} finally {
				// Forget state modified by delete/set even if the database rolled the change back.
				$this->config->clearCache(false);
			}
			throw new FileWritePolicyUnavailable();
		}
	}

	/**
	 * Canonical representation shared by persistence validation and the future admin endpoint.
	 *
	 * @param string $mode off or allowlist.
	 * @param string|array<mixed> $extensions Raw admin input, never a regular expression or path.
	 * @param bool $allowExtensionless Explicit no-extension choice.
	 * @return array{version:int, mode:string, extensions:list<string>, allowExtensionless:bool} Canonical value.
	 * @throws InvalidArgumentException For an unsupported mode, malformed token or too many unique extensions.
	 */
	private function normalize(string $mode, string|array $extensions, bool $allowExtensionless): array {
		if (!in_array($mode, ['off', 'allowlist'], true)) {
			throw new InvalidArgumentException(FileTypeMessages::invalid());
		}
		if (is_string($extensions)) {
			$extensions = trim($extensions);
			$extensions = $extensions === '' ? [] : preg_split('/\s*(?:,|\R)\s*/', $extensions);
			if (is_array($extensions) && end($extensions) === '') {
				array_pop($extensions);
			}
		}
		if (!is_array($extensions) || !array_is_list($extensions)) {
			throw new InvalidArgumentException(FileTypeMessages::invalid());
		}
		$unique = [];
		foreach ($extensions as $extension) {
			if (!is_string($extension)) {
				throw new InvalidArgumentException(FileTypeMessages::invalid());
			}
			$extension = strtolower(trim($extension));
			if (str_starts_with($extension, '.')) {
				$extension = substr($extension, 1);
			}
			if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,31}\z/D', $extension) !== 1) {
				throw new InvalidArgumentException(FileTypeMessages::invalid());
			}
			$unique[$extension] = $extension;
			if (count($unique) > self::MAX_EXTENSIONS) {
				throw new InvalidArgumentException(FileTypeMessages::invalid());
			}
		}
		$extensions = array_values($unique);
		sort($extensions, SORT_STRING);
		return ['version' => 1, 'mode' => $mode, 'extensions' => $extensions, 'allowExtensionless' => $allowExtensionless];
	}
}
