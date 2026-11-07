<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Writing\{PollWriteEffectFlow, PollWritePreviewFlow, PollWritePlan, PollWriteRequest};

/** Test-only facade selecting two different production flows for the shared data providers. */
final class PollsMutationFlows {
	/** Effect-free production worker. */
	public PollWritePreviewFlow $preview;
	/** Confirmed production worker. */
	public PollWriteEffectFlow $effect;
	/**
	 * @param PollsMutationScene $scene Native port double.
	 * @param PollWritePlan $plans Real HMAC plans.
	 * @return void
	 */
	public function __construct(PollsMutationScene $scene, PollWritePlan $plans) {
		$this->preview = new PollWritePreviewFlow($scene, $plans);
		$this->effect = new PollWriteEffectFlow($scene, $plans);
	}

	/**
	 * @param string $tool Reviewed tool.
	 * @param array<string,mixed> $args Arguments.
	 * @param string $uid Actor.
	 * @param bool $preview Which production entry point to exercise.
	 * @return array<string,mixed> Plan or receipt.
	 * @throws \Throwable If validation, admission or native work fails.
	 */
	public function run(string $tool, array $args, string $uid, bool $preview): array {
		$r = PollWriteRequest::of($tool, $args);
		return $preview ? $this->preview->plan($r, $uid) : $this->effect->execute($r, $args, $uid);
	}
}
