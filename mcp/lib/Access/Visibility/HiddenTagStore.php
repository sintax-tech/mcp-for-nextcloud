<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

use OCP\IConfig;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;

/** Stores and validates hidden tag configuration independently of node visibility caches. */
final class HiddenTagStore {
	public const CONFIG_KEY = 'hidden_system_tags';

	/** @var list<string>|null Confirmed configured IDs; null until a read succeeds. */
	private ?array $hiddenTagIds = null;
	/** @var bool Whether the latest configuration read was invalid or unavailable. */
	private bool $configInvalid = false;

	/**
	 * Bind native configuration and optional system-tag validation without reading either service.
	 * @param IConfig $config Native app configuration.
	 * @param ISystemTagManager|null $tagManager Optional system-tag metadata validator.
	 */
	public function __construct(private IConfig $config, private ?ISystemTagManager $tagManager) {
	}

	/**
	 * Read and memoize a valid selection; an unavailable read is retried on the next call.
	 * @return list<string> Configured IDs, or an empty list when invalid or unavailable; consult isConfigurationValid().
	 */
	public function getHiddenTagIds(): array {
		if ($this->hiddenTagIds !== null) {
			return $this->hiddenTagIds;
		}
		try {
			$raw = $this->config->getAppValue('mcp', self::CONFIG_KEY, '[]');
		} catch (\Throwable) {
			// Retry on the next check; an unavailable config never means no exclusions.
			$this->configInvalid = true;
			return [];
		}
		// Objects stay objects: decoded as arrays, {} and {"0":"42"} would pass for lists.
		$decoded = json_decode($raw);
		$ids = [];
		$valid = is_array($decoded);
		foreach ($valid ? $decoded : [] as $id) {
			$str = is_int($id) || is_string($id) ? (string)$id : '';
			if (!ctype_digit($str) || (int)$str <= 0) {
				$valid = false;
				break;
			}
			$ids[] = $str;
		}
		// An unreadable value is not "no tags": the guard refuses every node until an admin saves again,
		// and the stored value is left untouched.
		$this->configInvalid = !$valid;
		$this->hiddenTagIds = $valid ? array_values(array_unique($ids)) : [];
		return $this->hiddenTagIds;
	}

	/**
	 * Report the validity established by the most recent read, without triggering another read.
	 * @return bool Whether the current read state is valid.
	 */
	public function isConfigurationValid(): bool {
		return !$this->configInvalid;
	}

	/**
	 * Validate and persist a selection while retaining the exact read-failure and metadata fallback rules.
	 * @param list<string|int> $tagIds Requested positive tag IDs.
	 * @param \Closure(): list<string> $read Virtual facade read used only when the current configuration must be checked.
	 * @return void
	 * @throws \InvalidArgumentException When an ID is not a positive integer.
	 * @throws HiddenTagsUnavailableException When the current configuration or system tags cannot be checked.
	 */
	public function setHiddenTagIds(array $tagIds, \Closure $read): void {
		$sanitized = [];
		foreach ($tagIds as $id) {
			$str = (string)$id;
			if (!ctype_digit($str) || (int)$str <= 0) {
				throw new \InvalidArgumentException('Tag ID must be a positive integer: ' . $str);
			}
			$sanitized[] = $str;
		}
		$sanitized = array_values(array_unique($sanitized));

		if ($this->tagManager !== null && $sanitized !== []) {
			try {
				$tags = $this->tagManager->getTagsByIds($sanitized);
				$existing = [];
				foreach ($tags as $tag) {
					if ($tag instanceof ISystemTag) {
						$existing[] = (string)$tag->getId();
					}
				}
				$sanitized = array_values(array_intersect($sanitized, $existing));
			} catch (\Throwable) {
				try {
					$all = $this->tagManager->getAllTags(null, false);
					$valid = [];
					foreach ($all as $t) {
						if ($t instanceof ISystemTag) {
							$valid[(string)$t->getId()] = true;
						}
					}
					$sanitized = array_values(array_filter($sanitized, static fn (string $id): bool => isset($valid[$id])));
				} catch (\Throwable $e) {
					throw new HiddenTagsUnavailableException('System tags could not be checked', 0, $e);
				}
			}
		}

		// Never replace exclusions after a failed read in this operation. Malformed
		// but readable values remain repairable through the admin panel.
		if ($this->hiddenTagIds === null && !$this->configInvalid) {
			($read)();
		}
		if ($this->hiddenTagIds === null && $this->configInvalid) {
			throw new HiddenTagsUnavailableException('Hidden tags configuration could not be read');
		}

		$this->config->setAppValue('mcp', self::CONFIG_KEY, json_encode($sanitized));
		$this->hiddenTagIds = $sanitized;
		$this->configInvalid = false;
	}

	/**
	 * Forget the request-local selection and read validity without changing persisted configuration.
	 * @return void
	 */
	public function clearCache(): void {
		$this->hiddenTagIds = null;
		$this->configInvalid = false;
	}
}
