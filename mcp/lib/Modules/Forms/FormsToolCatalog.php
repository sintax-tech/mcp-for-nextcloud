<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

/** The seven published Forms tools, in their original tools/list order. */
final class FormsToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> ordered tool classes */
	public const TOOLS = [
		Tools\ListForms::class,
		Tools\ReadForm::class,
		Tools\ListSubmissions::class,
		Tools\ReadSubmission::class,
		Tools\CreateForm::class,
		Tools\UpdateQuestion::class,
		Tools\UpdateOption::class,
	];

	/** Prevents construction of the ordered catalog. */
	private function __construct() {
	}
}
