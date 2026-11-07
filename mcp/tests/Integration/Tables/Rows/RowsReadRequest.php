<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Rows;

use Closure;
use InvalidArgumentException;

/** Executes MCP pages and the native oracle within one actor request, preserving each returned order. */
final class RowsReadRequest {
	/**
	 * @param Closure(array<string,mixed>):array<string,mixed> $mcp One registry page for the actor.
	 * @param Closure(array<string,mixed>):array<string,mixed> $native Native RowService response for that actor.
	 */
	public function __construct(private Closure $mcp, private Closure $native) {}

	/**
	 * @param array<string,mixed> $input Arguments and optional page bound.
	 * @return array{pages:list<array<string,mixed>>} Unmodified MCP pages in cursor order.
	 */
	public function pages(array $input): array {
		$pages = [];
		$arguments = $input['arguments'];
		for ($i = 0; $i < (int)($input['maxPages'] ?? 50); $i++) {
			try {
				$result = ($this->mcp)($arguments);
			} catch (InvalidArgumentException) {
				$pages[] = ['refused' => true];
				break;
			}
			$pages[] = $result;
			$page = ($result['isError'] ?? false) ? null : json_decode($result['content'][0]['text'], true);
			if (!is_array($page) || ($page['nextCursor'] ?? null) === null) {
				break;
			}
			$arguments['cursor'] = $page['nextCursor'];
		}
		return ['pages' => $pages];
	}

	/**
	 * @param array<string,mixed> $target Native table or view identifier.
	 * @return array<string,mixed> RowService's order and cells, or its refusal.
	 */
	public function native(array $target): array {
		return ($this->native)($target);
	}

	/**
	 * @param array<string,mixed> $input MCP arguments with a native target.
	 * @return array{pages:list<array<string,mixed>>,native:array<string,mixed>} Both observations in this request, without sorting either.
	 */
	public function compare(array $input): array {
		$pages = $this->pages($input);
		return $pages + ['native' => $this->native($input['arguments'])];
	}
}
