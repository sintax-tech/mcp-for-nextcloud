<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Results;

use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\ToolFailure;

/** Validates a cursor's MAC before header reads, then lets the caller compare freshly read header state. */
final class SubmissionCursor {
	public const MAX_OFFSET = 50000;
	/** Binds the public offset/state to the actor, form, mode, version and page size.
	 * @param PlanState $mac Instance MAC.
	 * @param string $uid Authenticated actor bytes.
	 * @param array $bound Authorized form/mode/version/limit.
	 * @param int $maxOffset Symmetric ceiling, reducible for discriminant tests.
	 */
	public function __construct(private PlanState $mac, private string $uid, private array $bound, private int $maxOffset = self::MAX_OFFSET) {
	}
	/** Encodes a reachable next offset, never a cursor its decoder would refuse.
	 * @param int $offset Next page start.
	 * @param string $state Keyed header digest.
	 * @return string Authenticated cursor.
	 */
	public function encode(int $offset, string $state): string {
		if ($offset < 0 || $offset > $this->maxOffset) { throw new \LogicException('Cursor offset out of range'); }
		$payload = ['o' => $offset, 's' => $state];
		return 'v1.' . rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=') . '.' . $this->sign($payload);
	}
	/** Verifies syntax/MAC before native header fetch; null means the first page.
	 * @param string|null $cursor Client cursor.
	 * @return array{o:int,s:?string} Offset and expected state.
	 */
	public function decode(?string $cursor): array {
		if ($cursor === null) { return ['o' => 0, 's' => null]; }
		$parts = explode('.', $cursor);
		if (strlen($cursor) > 512 || count($parts) !== 3 || $parts[0] !== 'v1') { throw new ToolFailure(ResultsMessages::stale()); }
		$raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
		$data = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($data) || array_keys($data) !== ['o', 's'] || !is_int($data['o']) || $data['o'] < 0 || $data['o'] > $this->maxOffset
			|| !is_string($data['s']) || preg_match('/^[a-f0-9]{64}$/D', $data['s']) !== 1 || !hash_equals($this->sign($data), $parts[2])) {
			throw new ToolFailure(ResultsMessages::stale());
		}
		return $data;
	}
	/** MACs only canonical technical state with actor bytes preserved.
	 * @param array $payload Offset and keyed state.
	 * @return string Cursor MAC.
	 */
	private function sign(array $payload): string { return $this->mac->of('forms_list_submissions-v1', base64_encode($this->uid), ['bound' => $this->bound, 'cursor' => $payload]); }
}
