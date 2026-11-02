<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanText;
use OCA\Mcp\Modules\Polls\Creation\CreatePlan;

/** The readable body of a Polls plan; only the private creation writes, so only its plans have a body here. */
final class PollsPlanRenderer {
	/**
	 * @param string $tool tool name
	 * @param array<string,mixed> $plan structured preview
	 * @return string|null body, or null so that a non-writing tool gets the generic fallback
	 */
	public function render(string $tool, array $plan): ?string {
		return $tool === CreatePlan::TOOL ? $this->create($plan) : null;
	}

	/**
	 * Renders every submitted value inertly through the original PlanText contract.
	 * @param array<string,mixed> $plan structured preview
	 * @return string readable body only; registry supplies title/warnings/footer
	 */
	private function create(array $plan): string {
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
