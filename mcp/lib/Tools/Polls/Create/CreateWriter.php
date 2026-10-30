<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tools\Polls\Create;

use OCA\Mcp\Tools\Polls\{PollsSurface, PollsContentValues, PollsRefusal};
/** Executes the accepted stages once; no rollback, recovery query, inferred ID or retry. */
class CreateWriter {
	/**
	 * Receives focused native factories/guards and disposable value normalization.
	 * @param CreateNative $native native mechanism
	 * @param CreateNormalizer $normalizer public observable option values
	 * @return void
	 */
	public function __construct(private CreateNative $native, private CreateNormalizer $normalizer) {
	}
	/**
	 * Writes a new poll and initial options; throws into the effect-aware outer boundary.
	 * @param array<string,mixed> $request immutable input
	 * @param list<array<string,mixed>> $effect confirmed option predictions
	 * @param object $session native session
	 * @param string $uid authenticated new owner
	 * @param bool $nine native public layout
	 * @param CreateAttempt $attempt local effect/receipt state
	 * @return void
	 * @throws \Throwable on any native failure or observable divergence, potentially after effects
	 */
	public function write(array $request, array $effect, object $session, string $uid, bool $nine, CreateAttempt $attempt): void {
		$this->native->check($session, $uid);
		$polls = $this->native->polls($session);
		$attempt->stage = 'poll';
		$attempt->started = $attempt->pending = true;
		$created = $nine ? $polls->add($request['type'], $request['title'], null, 'simple') : $polls->add($request['type'], $request['title'], 'simple');
		$class = PollsSurface::POLL;
		if (!$created instanceof $class || !is_int($created->getId()) || $created->getId() < 1) {
			throw PollsRefusal::unavailable();
		}
		$id = $created->getId();
		$poll = $this->native->readback($id, $request, $session, $uid, $nine);
		$attempt->receipt = ['pollId' => $id, 'url' => PollsContentValues::url($poll->getVoteUrl()), 'type' => $request['type'], 'title' => $request['title'], 'observedDefaults' => CreateDefaults::read($poll, $nine), 'options' => []];
		$attempt->pending = false;
		$seen = [];
		foreach ($request['options'] as $index => $row) {
			$attempt->stage = 'option:' . ($index + 1);
			$this->native->readback($id, $request, $session, $uid, $nine);
			$options = $this->native->options($session);
			$simple = $this->normalizer->simple($row, $request['type']);
			$this->native->check($session, $uid);
			$attempt->pending = true;
			$added = $options->add($id, $simple, false);
			$optionClass = PollsSurface::OPTION;
			if (!$added instanceof $optionClass
				|| !is_int($added->getId())
				|| $added->getId() < 1
				|| $added->getPollId() !== $id
				|| isset($seen[$added->getId()])
				|| $this->normalizer->value($added) !== $effect[$index]) {
				throw PollsRefusal::unavailable();
			}
			// get() alone is not ACL: reauthorize its parent before the option readback.
			$this->native->readback($id, $request, $session, $uid, $nine);
			$back = $this->native->options($session)->get($added->getId());
			$this->native->receiptAccess($session, $uid);
			if (!$back instanceof $optionClass || $back->getId() !== $added->getId() || $back->getPollId() !== $id || $this->normalizer->value($back) !== $effect[$index]) {
				throw PollsRefusal::unavailable();
			}
			$seen[$added->getId()] = true;
			$attempt->receipt['options'][] = ['inputIndex' => $index, 'id' => $back->getId()] + $this->normalizer->value($back);
			$attempt->pending = false;
		}
		$attempt->stage = 'complete';
		$attempt->pending = true;
		$this->native->readback($id, $request, $session, $uid, $nine);
		$attempt->pending = false;
	}
}
