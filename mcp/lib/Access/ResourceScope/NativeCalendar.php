<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

/** Native calendar fields needed to enumerate resources and explain access, without tool-layer types. */
final readonly class NativeCalendar {
	/**
	 * @param int $id Stable backend calendar identifier.
	 * @param string $name Native display name.
	 * @param bool $writable Whether this user may modify calendar objects.
	 */
	public function __construct(public int $id, public string $name, public bool $writable) {
	}
}
