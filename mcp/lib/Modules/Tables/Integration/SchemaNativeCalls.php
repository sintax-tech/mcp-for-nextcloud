<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Tables\Presentation\SchemaMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** New schema tools use the common not-found answer without changing the legacy Tables read tools. */
final class SchemaNativeCalls {
	/**
	 * @template T
	 * @param callable():T $call Native read.
	 * @return T Authorized result.
	 */
	public static function run(callable $call): mixed {
		try { return $call(); }
		catch (ToolFailure $error) { throw $error; }
		catch (\Throwable $error) {
			throw new ToolFailure(TablesCalls::isNotFound($error) ? CommonMessages::notFound() : SchemaMessages::incompatible());
		}
	}
}
