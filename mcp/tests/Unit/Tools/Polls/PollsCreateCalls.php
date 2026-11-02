<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Modules\Polls\Creation\CreatePollHandler;
use OCA\Mcp\Modules\Polls\Creation\CreatePollPreview;

/** The plan and the confirmed creation the tests called on the legacy handler, now two classes. */
final class PollsCreateCalls {
	public function __construct(public readonly CreatePollHandler $creates, public readonly CreatePollPreview $plans) {
	}

	/** @return array<string,mixed> structured plan */
	public function preview(array $arguments, string $userId): array {
		return $this->plans->plan($arguments, $userId);
	}

	/** @return array<string,mixed> MCP envelope with the receipt */
	public function handle(array $arguments, string $userId): array {
		return $this->creates->handle($arguments, $userId);
	}
}
