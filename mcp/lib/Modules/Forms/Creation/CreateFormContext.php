<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Creation;

/** Holds one operation's explicit fresh native graph; never persisted or reused across requests. */
final class CreateFormContext {
	/** Receives the exact collaborators used for native calls and authorized readback.
	 * @param string $version Exact supported Forms version.
	 * @param object $controller Fresh native ApiController.
	 * @param object $config Fresh native ConfigService with Forms-scoped configuration.
	 * @param object $service Exact identity-proven FormsService.
	 * @param object $forms Native FormMapper, read-only to MCP.
	 * @param object $questions Native QuestionMapper, read-only to MCP.
	 * @param object $options Native OptionMapper, read-only to MCP.
	 * @param object $paths Native FilePathHelper, only for non-mutating title safety reads.
	 * @param object|null $submission Fresh unused SubmissionService, retained for graph identity contracts.
	 */
	public function __construct(public readonly string $version, public readonly object $controller, public readonly object $config,
		public readonly object $service, public readonly object $forms, public readonly object $questions,
		public readonly object $options, public readonly object $paths, public readonly ?object $submission = null) {
	}
}
