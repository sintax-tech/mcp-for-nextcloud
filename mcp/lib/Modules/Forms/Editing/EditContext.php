<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Editing;

/** Holds the exact per-operation graph, never a cached controller or synthetic user session. */
final class EditContext {
	/** Captures native collaborators for one admitted request.
	 * @param string $version Exact supported pin.
	 * @param object $controller Fresh ApiController.
	 * @param object $config Fresh Forms-scoped configuration.
	 * @param object $service Identity-proven FormsService.
	 * @param object $questions Native question reader.
	 * @param object $options Native option reader.
	 * @param object $shares Native share reader; no display-name projection.
	 */
	public function __construct(public readonly string $version, public readonly object $controller, public readonly object $config,
		public readonly object $service, public readonly object $questions, public readonly object $options, public readonly object $shares) { }
}
