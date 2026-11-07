<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

/** Admin-managed persistent hidden tag selection and its request-local read validity. */
interface HiddenTagConfiguration {
	/**
	 * Read configured hidden tags without replacing an invalid or unavailable stored value.
	 * @return list<string> Unique positive IDs; empty for no selection or an invalid read, distinguished by isConfigurationValid().
	 */
	public function getHiddenTagIds(): array;

	/**
	 * Read the configured selection and distinguish valid empty settings from an unreadable or malformed value.
	 * @return bool Whether the stored selection can be used for exclusion checks.
	 */
	public function isConfigurationValid(): bool;

	/**
	 * Validate and save hidden tags, retaining the saved selection and clearing node caches without virtual invalidation.
	 * @param list<string|int> $tagIds Positive IDs; an empty selection needs no system-tag lookup.
	 * @return void
	 * @throws \InvalidArgumentException When an ID is not a positive integer.
	 * @throws HiddenTagsUnavailableException When current configuration or system tags cannot be checked.
	 */
	public function setHiddenTagIds(array $tagIds): void;
}
