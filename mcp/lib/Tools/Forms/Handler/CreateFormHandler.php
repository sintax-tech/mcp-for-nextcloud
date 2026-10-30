<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Handler;

use OCA\Mcp\Tools\Forms\Creation\CreateFormFlow;

/** Serves only aggregate creation of a new private native form and its initial structure. */
final class CreateFormHandler {
	/** Receives the focused creation flow.
	 * @param CreateFormFlow $flow Native creation preflight, plan and confirmed sequence.
	 */
	public function __construct(private CreateFormFlow $flow) {
	}
	/** Delegates a zero-sink preview.
	 * @param array<string,mixed> $arguments Complete creation request.
	 * @param string $uid Authenticated actor.
	 * @return array<string,mixed> Full plan.
	 */
	public function preview(array $arguments, string $uid): array { return $this->flow->preview($arguments, $uid); }
	/** Delegates a confirmed creation without accepting existing IDs.
	 * @param array<string,mixed> $arguments Complete creation request with confirmation.
	 * @param string $uid Authenticated actor.
	 * @return array<string,mixed> Observed result or partial/uncertain receipt.
	 */
	public function handle(array $arguments, string $uid): array { return $this->flow->handle($arguments, $uid); }
}
