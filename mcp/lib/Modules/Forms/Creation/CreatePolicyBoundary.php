<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Creation;

use OCA\Mcp\Modules\Forms\Presentation\CreateFormMessages;

use OCA\Mcp\Access\Policy\PolicyResolver;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Reassembles one non-observing policy boundary without joining or replacing the endpoint snapshot. */
class CreatePolicyBoundary {
	/** Receives the existing authoritative resolver, not a second policy implementation.
	 * @param PolicyResolver $resolver Native MCP policy resolution with its documented cache limits.
	 */
	public function __construct(private PolicyResolver $resolver) {
	}
	/** Requires effective create permission, closing only this boundary's context on every outcome.
	 * @param string $uid Authenticated actor.
	 * @return void
	 * @throws ToolFailure For denied, unknown or unavailable policy.
	 */
	public function assertAllowed(string $uid): void {
		try {
			$context = $this->resolver->begin($uid, [], [], false);
			try {
				if ($context->resolveEffective('forms', 'create')->allowed !== true) { throw new ToolFailure(CreateFormMessages::userDenied()); }
			} finally { $context->close(); }
		} catch (ToolFailure $error) {
			throw $error;
		} catch (\Throwable $error) {
			throw new ToolFailure(CreateFormMessages::refused(), 0, $error);
		}
	}
}
