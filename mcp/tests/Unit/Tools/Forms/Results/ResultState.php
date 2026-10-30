<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Results;

/** Mutable native-store state records which read surface the real reader reaches. */
final class ResultState {
	public array $headers = [];
	public array $questions = [];
	public array $options = [];
	public array $answers = [];
	public array $calls = [];
	public ?string $fail = null;
	public ?\Closure $afterAnswers = null;
	/** Executes only the requested native-style read and preserves exception propagation.
	 * @param string $kind Native mapper kind.
	 * @param string $method Native method.
	 * @param array $args Positional native arguments.
	 * @return mixed Native entities.
	 */
	public function read(string $kind, string $method, array $args): mixed {
		$key = $kind . '::' . $method; $this->calls[] = [$key, $args];
		if ($this->fail === $kind) { throw new \RuntimeException('private database path'); }
		if ($kind === 'SubmissionMapper' && $method === 'findByForm') {
			$items = array_filter($this->headers, static fn ($row): bool => $row->getFormId() === $args[0] && (!$args[1] || $row->getUserId() === $args[1]));
			return array_slice(array_values($items), 0, $args[3]);
		}
		if ($kind === 'SubmissionMapper') {
			foreach ($this->headers as $row) { if ($row->getId() === $args[0]) { return $row; } }
			throw new \OCP\AppFramework\Db\DoesNotExistException('private missing id');
		}
		if ($kind === 'QuestionMapper') { return $this->questions; }
		if ($kind === 'OptionMapper') { return $this->options[$args[0]] ?? []; }
		$value = $this->answers[$args[0]] ?? [];
		if ($this->afterAnswers !== null) { ($this->afterAnswers)(); }
		return $value;
	}
}

/** Native mapper-shaped boundary double, deliberately retaining the truthy UID filter. */
final class ResultMapper {
	/** Receives isolated mutable fixture state.
	 * @param ResultState $state Synthetic data store.
	 * @param string $kind Mapper kind.
	 */
	public function __construct(private ResultState $state, private string $kind) {
	}
	/** Records all native reads, never pretending a DB error is empty.
	 * @param string $method Native method name.
	 * @param array $args Native arguments.
	 * @return mixed Native-shaped result.
	 */
	public function __call(string $method, array $args): mixed { return $this->state->read($this->kind, $method, $args); }
}
