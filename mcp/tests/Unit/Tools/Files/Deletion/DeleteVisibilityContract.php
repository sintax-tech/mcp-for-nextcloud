<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolRegistry;
use PHPUnit\Framework\Assert;

/** Uses the existing tag matrix's real registry to cover direct and inherited exclusion for the new tool. */
final class DeleteVisibilityContract {
	/**
	 * @param ToolRegistry $registry Policy-admitted registry of the original visibility matrix.
	 * @return void Hidden resources answer like absent in preview and direct confirmation.
	 */
	public static function verify(ToolRegistry $registry): void {
		foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $path) {
			foreach ([false, true] as $confirm) {
				$result = $registry->call('files_delete', ['path' => $path, 'confirm' => $confirm], 'alice');
				Assert::assertTrue($result['isError'] ?? false);
				Assert::assertSame(CommonMessages::notFound(), $result['content'][0]['text']);
			}
		}
	}
}
