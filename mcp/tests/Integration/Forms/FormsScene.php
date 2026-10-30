<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;

/** Stable disposable actor names and the identifiers created through native OCS setup. */
final class FormsScene {
	public const OWNER = 'mcp-it-forms-owner';
	public const READER = 'mcp-it-forms-reader';
	public const EDITOR = 'mcp-it-forms-editor';
	public const OUTSIDE = 'mcp-it-forms-outside';
	public const GROUP = 'mcp-it-forms-group';

	/** Reads only the disposable scene identifiers.
	 * @return array<string,mixed> Native ids, never auth tokens or private response data.
	 */
	public static function read(): array { return json_decode(file_get_contents('/tmp/mcp-it-forms-scene.json'), true, 512, JSON_THROW_ON_ERROR); }
}
