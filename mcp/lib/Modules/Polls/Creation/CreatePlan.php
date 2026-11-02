<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Creation;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Polls\Presentation\CreateMessages;

/** Stateless signed preview; full scalar request/effect is bound before any presentation cut. */
class CreatePlan {
	/** Tool name shared by definition and handler. */
	public const TOOL = 'polls_create_poll';
	/**
	 * Receives the existing instance-secret MAC provider.
	 * @param PlanState $states existing stateless plan mechanism
	 * @return void
	 */
	public function __construct(private PlanState $states) {
	}
	/**
	 * Builds a side-effect-free structured plan and stable fingerprint.
	 * @param array<string,mixed> $request validated full input
	 * @param array<int,array<string,mixed>> $effect predicted public option values
	 * @param string $uid actor
	 * @param string $version installed release
	 * @param bool $nine native layout
	 * @param bool $changed whether a confirmation requires a new plan
	 * @return array<string,mixed> structured preview
	 */
	public function of(array $request, array $effect, string $uid, string $version, bool $nine, bool $changed = false): array {
		$plan = ['action'=>self::TOOL,'target'=>['owner'=>$uid,'shared'=>false],'request'=>$request,'options'=>$effect,
			'persistence'=>CreateDefaults::fingerprint($nine),'version'=>$version,'serverTimezone'=>$nine?'UTC':date_default_timezone_get(),
			'notes'=>CreateMessages::notes(),'changed'=>$changed];
		$state = $plan; unset($state['changed']);
		$plan[PlanState::ARGUMENT] = $this->states->of(self::TOOL,$uid,$state);
		if ($changed) { $plan['warnings'] = [['message' => CreateMessages::planChanged($version)]]; }
		return $plan;
	}
}
