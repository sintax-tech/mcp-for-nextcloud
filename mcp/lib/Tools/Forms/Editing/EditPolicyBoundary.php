<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Editing;

use OCA\Mcp\Service\Policy\PolicyResolver;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Renews effective edit policy without replacing the endpoint's external operation snapshot. */
class EditPolicyBoundary {
	/** Receives the existing resolver.
	 * @param PolicyResolver $resolver Authoritative MCP resolver.
	 */
	public function __construct(private PolicyResolver $resolver) { }
	/** Requires strict effective edit admission and closes only its own context.
	 * @param string $uid Authenticated actor.
	 * @return void
	 */
	public function assertAllowed(string $uid): void {
		try {
			$context = $this->resolver->begin($uid, [], [], false);
			try { if ($context->resolveEffective('forms', 'edit')->allowed !== true) { throw new ToolFailure(EditMessages::refused()); } }
			finally { $context->close(); }
		} catch (\Throwable $error) { throw new ToolFailure(EditMessages::refused(), 0, $error); }
	}
}
