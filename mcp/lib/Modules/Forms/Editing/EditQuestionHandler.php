<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Editing;

use OCA\Mcp\Modules\Forms\Editing\EditFlow;

/** Delegates one point tool to the focused edit flow, never direct native logic. */
final class EditQuestionHandler {
	/** Receives point editing coordination.
	 * @param EditFlow $flow Native edit admission/plan/sink.
	 */
	public function __construct(private EditFlow $flow) { }
	/** Delegates a zero-effect preview.
	 * @param array<string,mixed> $args Closed request.
	 * @param string $uid Actor.
	 * @return array<string,mixed> Complete plan.
	 */
	public function preview(array $args, string $uid): array { return (new UpdateQuestionPreview($this->flow))->plan($args, $uid); }
	/** Delegates one confirmed edit.
	 * @param array<string,mixed> $args Closed request and confirmation.
	 * @param string $uid Actor.
	 * @return array<string,mixed> MCP result.
	 */
	public function handle(array $args, string $uid): array { return $this->flow->handle($args, $uid, false); }
}
