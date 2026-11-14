<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

/** Active server coverage, deliberately independent of the installation range in info.xml. */
final class TestedNextcloud {
	/** @var list<string> Only these server majors participate in source contracts and generators. */
	public const MAJORS = ['34', '35'];

	/** Static policy; no instances. */
	private function __construct() {
	}
}
