<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Inventory;

use PHPUnit\Framework\Assert;

/** One explicit delete sink, one empty-folder undo sink, and no autonomous backup removal. */
final class FilesDeletionPolicy {
	/** @param list<string> $files Inventory paths. @param string $undoOwner Exact undo sink. @param string $deleteOwner Exact files_delete sink. @return void */
	public static function assertNodes(array $files, string $undoOwner, string $deleteOwner): void {
		foreach ($files as $file) {
			$source = (string)file_get_contents($file);
			preg_match_all('/(?:->|\?->)\s*delete\s*\(\s*\)/', $source, $calls, PREG_OFFSET_CAPTURE);
			if ($calls[0] === []) { continue; }
			$owner = realpath($file);
			$method = $owner === realpath($deleteOwner) ? 'handle' : ($owner === realpath($undoOwner) ? 'undoBatch' : null);
			Assert::assertNotNull($method, basename($file) . ' calls Node::delete(); the MCP removes no file and no folder');
			Assert::assertCount(1, $calls[0], 'Only one deletion is allowed in each exact sink');
			$methodStart = strpos($source, 'function ' . $method);
			Assert::assertNotFalse($methodStart, 'Missing approved deletion method');
			Assert::assertGreaterThan($methodStart, $calls[0][0][1], 'Deletion must remain in the approved method');
			$hasNext = preg_match('/function\s+[A-Za-z_]/', $source, $next, PREG_OFFSET_CAPTURE, $methodStart + strlen('function '));
			$nextMethod = $hasNext ? $next[0][1] : false;
			Assert::assertTrue($nextMethod === false || $calls[0][0][1] < $nextMethod, 'Deletion escaped approved method');
		}
	}

	/** @param list<string> $files Files sources. @return void SQL variable names are not file maintenance. */
	public static function assertBackups(array $files): void {
		$tokenStore = FilesSources::sink(dirname(__DIR__, 3), 'OCA\\Mcp\\Checkout\\CheckoutTokenStore');
		foreach ($files as $file) {
			$ownSqlVariable = realpath($file) === realpath($tokenStore);
			$source = implode('', array_map(static fn ($token) => is_array($token) ? ($ownSqlVariable && $token[0] === T_VARIABLE && $token[1] === '$purge' ? '' : $token[1]) : $token, token_get_all((string)file_get_contents($file))));
			foreach (['purge', 'rotate', 'retention', 'prune', 'cleanup'] as $word) { Assert::assertStringNotContainsStringIgnoringCase($word, $source, basename($file) . ': backups are never cleaned up'); }
		}
	}

	/** @param list<string> $files Sources. @return void This is a textual origin check, not a dataflow proof. */
	public static function assertTemporaryUnlinks(array $files): void {
		foreach ($files as $file) {
			$source = (string)file_get_contents($file);
			if (preg_match('/\bunlink\s*\(/', $source)) { Assert::assertStringContainsString('getTemporaryFile(', $source, basename($file) . ': unlink requires a PHP temporary source'); }
		}
	}
}
