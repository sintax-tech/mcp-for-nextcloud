<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Creation;

use OCA\Mcp\Modules\Polls\Presentation\CreateMessages;

/** Per-call state, never persisted or shared between requests; IDs are retained only after authorized readback. */
final class CreateAttempt {
	/** @var bool a native sink may have begun */
	public bool $started = false;
	/** @var bool the current sink may have persisted without a confirmed receipt */
	public bool $pending = false;
	/** @var string fixed stage identifier without content */
	public string $stage = 'preflight';
	/** @var array<string,mixed> safe receipts already confirmed in this call */
	public array $receipt = [];
	/**
	 * Produces an honest whitelist envelope; counts concern this call, not native totals.
	 * @param string $status created/partial/uncertain/notCreated
	 * @param int $requested requested initial option stages
	 * @param bool $disclose whether current authorization permits retained receipt fields
	 * @return array<string,mixed> untrusted structured receipt
	 */
	public function result(string $status, int $requested, bool $disclose = true): array {
		$receipt = $disclose ? $this->receipt : [];
		$completed = count($receipt['options'] ?? []);
		return ['status'=>$status,'stage'=>$this->stage,'untrustedPollsData'=>true,'notes'=>CreateMessages::notes(),
			'message'=>$status === 'created' ? '' : ($status === 'partial' ? CreateMessages::partial() : CreateMessages::failure($this->started))] + $receipt
			+ ($disclose ? ['requested'=>$requested,'completed'=>$completed,'remaining'=>$requested-$completed] : []);
	}
}
