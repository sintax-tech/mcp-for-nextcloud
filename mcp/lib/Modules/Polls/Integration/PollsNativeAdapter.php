<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

use OCA\Mcp\Modules\Polls\Admission\PollsWriteAdmission;
use OCA\Mcp\Modules\Polls\Contracts\PollWriteNative;
use OCA\Mcp\Modules\Polls\Contracts\PollWriteStale;
use OCA\Mcp\Modules\Polls\PollsRefusal;

/** Authenticated mutation context, with fresh guards at every exposed native stage and final cleanup. */
final class PollsNativeAdapter implements PollWriteNative {
	/** Checked global session, never replaced by a second native identity. */
	private ?object $session = null;
	/** Exact independent grant of this call. */
	private string $operation = '';
	/** @param PollsServices $services Existing lazy resolver. @param PollsSessionGuard $guard Both native identities. @param PollsWriteAdmission $admission Fresh grant/app/guest checks. @param PollsMutationSnapshot $snapshots Complete metadata. @param PollsMutationWriter $writer Official sinks only. */
	public function __construct(private PollsServices $services, private PollsSessionGuard $guard,
		private PollsWriteAdmission $admission, private PollsMutationSnapshot $snapshots, private PollsMutationWriter $writer) {}
	/** {@inheritDoc} */
	public function open(string $uid, string $operation): void {
		$this->guard->caller($uid);
		$this->admission->check($uid, $operation);
		$this->operation = $operation;
		$this->session = $this->services->session();
		$this->session->cleanSession();
		$this->guard->native($this->session, $uid);
	}
	/** {@inheritDoc} */
	public function snapshot(string $uid, array $request): array {
		if ($this->session === null) { throw PollsRefusal::unavailable(); }
		$this->session->cleanSession();
		$this->guard->native($this->session, $uid);
		$v = $this->admission->check($uid, $this->operation, $request['poll_id']);
		$s = $this->snapshots->read($this->session, $uid, $request, $v);
		$this->guard->native($this->session, $uid);
		$this->admission->check($uid, $this->operation, $request['poll_id']);
		$this->snapshots->poll($this->session, $request['poll_id']);
		return $s;
	}
	/** {@inheritDoc} */
	public function mutate(string $uid, array $request, array $before, \Closure $attempted): void {
		$this->writer->write($this->session ?? throw PollsRefusal::unavailable(), $request,
			version_compare($before['version'], '9.0.0', '>='), function () use ($uid, $request, $before, $attempted): void {
				if ($this->snapshot($uid, $request) !== $before) { throw new PollWriteStale(); }
				$attempted();
			});
	}
	/** {@inheritDoc} */
	public function close(): void {
		$session = $this->session;
		$this->session = null;
		$this->operation = '';
		$session?->cleanSession();
	}
}
