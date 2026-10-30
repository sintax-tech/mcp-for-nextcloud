<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion;

/** Definition owned by the deletion feature; the large Files module only registers it. */
final class DeleteDefinition {
	/** @return array<string, mixed> The registry adds the common confirmation property and write annotations. */
	public static function get(): array {
		return ['name' => 'files_delete', 'module' => 'files', 'operation' => 'delete',
			'description' => DeleteMessages::tool(), 'inputSchema' => ['type' => 'object',
				'properties' => ['path' => ['type' => 'string', 'minLength' => 1],
					'confirm_shared' => ['type' => 'boolean', 'description' => 'Explicitly confirm the shared impact described in the preview. Other people may lose access; native recovery is not guaranteed.'],
					'etag' => ['type' => 'string'], 'id' => ['type' => 'integer', 'minimum' => 1]],
				'required' => ['path'], 'additionalProperties' => false]];
	}
}
