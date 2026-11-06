<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Writing;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Polls\Editing\PollConfiguration;
use OCA\Mcp\Modules\Polls\Options\OptionChanges;
use OCA\Mcp\Modules\Polls\Sharing\PollShareChanges;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteMessages;

/** Builds complete stateless plans and compares only declared observable effects. */
final class PollWritePlan {
	/** @param PlanState $states Instance-secret plan authenticator. */
	public function __construct(private PlanState $states) {}
	/** @param array<string,mixed> $r Request. @param array<string,mixed> $s Fresh snapshot. @return array<string,mixed> Pure effect. */
	public function effect(array $r, array $s): array {
		return match (true) {
			isset($r['changes']), isset($r['state']) => PollConfiguration::effect($r, $s),
			str_contains($r['tool'], 'option') => OptionChanges::effect($r, $s),
			default => PollShareChanges::effect($r, $s),
		};
	}
	/** @param string $uid Actor. @param array<string,mixed> $r Request. @param array<string,mixed> $s Snapshot. @return array<string,mixed> Safe complete plan. */
	public function of(string $uid, array $r, array $s): array {
		$effect = $this->effect($r, $s);
		$shown = ['request' => $r, 'snapshot' => $s, 'effect' => $effect];
		if (strlen(json_encode($shown, JSON_THROW_ON_ERROR)) > 65536) { PollWriteRequest::invalid('arguments'); }
		return ['action' => $r['tool'], 'target' => $s['poll'], 'request' => $r,
			'shared' => true, 'before' => $effect['before'], 'after' => $effect['after'],
			'noOp' => $effect['noop'], 'notes' => [...PollsWriteMessages::notes(), ...$effect['notes']],
			'plan_state' => $this->states->of($r['tool'], $uid, $shown)];
	}
	/** @param array<string,mixed> $r Request. @param array<string,mixed> $before Approved snapshot. @param array<string,mixed> $now Fresh readback. @return bool Complete observable effect. */
	public function matches(array $r, array $before, array $now): bool {
		if ($now['poll']['id'] !== $before['poll']['id']) { return false; }
		return match (true) {
			isset($r['changes']), isset($r['state']) => PollConfiguration::matches($r, $before, $now),
			str_contains($r['tool'], 'option') => OptionChanges::matches($r, $before, $now),
			default => PollShareChanges::matches($r, $before, $now),
		};
	}
	/** @param array<string,mixed> $r Request. @param array<string,mixed> $before Approved state. @param array<string,mixed> $now Fresh state. @return string Partial only when a requested changed field is actually observed. */
	public function status(array $r, array $before, array $now): string {
		if ($this->matches($r, $before, $now)) { return 'applied'; }
		if (isset($r['changes'])) {
			foreach ($r['changes'] as $key => $value) {
				if (($before['configuration'][$key] ?? null) !== $value && ($now['configuration'][$key] ?? null) === $value) { return 'partial'; }
			}
		}
		if (isset($r['option_ids'])) {
			foreach ($this->effect($r, $before)['after'] as $wanted) {
				$old = OptionChanges::find($before['options'], $wanted['id']);
				foreach ($now['options'] as $observed) {
					if ($observed['id'] === $wanted['id'] && $old['order'] !== $wanted['order'] && $observed['order'] === $wanted['order']) { return 'partial'; }
				}
			}
		}
		return 'uncertain';
	}
}
