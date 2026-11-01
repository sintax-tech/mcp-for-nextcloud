<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls\Create;

use OCA\Mcp\Mcp\{Confirmation\PlanState,Confirmation\PlanText};

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
	/**
	 * Renders every submitted value inertly through the original PlanText contract.
	 * @param array<string,mixed> $plan structured preview
	 * @return string readable body only; registry supplies title/warnings/footer
	 */
	public static function render(array $plan): string {
		$lines = ['- '.PlanText::inline((string)$plan['request']['title'],128),
			'- '.PlanText::inline((string)$plan['request']['type']),'- '.PlanText::inline((string)$plan['target']['owner']),
			'- version: '.PlanText::inline((string)$plan['version'],2000)];
		foreach ($plan['options'] as $index=>$row) {
			$requested = $plan['request']['options'][$index];
			if (isset($requested['text'])) { $lines[] = '- #'.($index+1).' '.PlanText::inline((string)$requested['text'],1024); }
			$lines[] = '- #'.($index+1).' '.PlanText::inline($row['text'],1024).' ('.$row['timestamp'].', '.$row['duration'].'s)';
		}
		foreach ($plan['persistence']['observable'] as $key=>$value) {
			$shown = $key === 'description' && $value === '' ? \OCA\Mcp\L10n\Translator::t('No description') : (is_scalar($value) ? (string)$value : 'null');
			$lines[] = '- '.PlanText::inline($key).': '.PlanText::inline($shown,2000);
		}
		foreach ($plan['notes'] as $note) { $lines[] = '- '.PlanText::inline($note,1000); }
		return implode("\n",$lines);
	}
}
