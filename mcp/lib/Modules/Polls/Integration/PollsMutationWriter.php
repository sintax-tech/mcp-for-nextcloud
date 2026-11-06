<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** Calls exactly one official service method after the final guard, never retries or writes through a mapper. */
final class PollsMutationWriter {
	/** @param PollsNativeFactory $factory Fresh service factory. @param PollsMutationSnapshot $snapshots Authorized metadata lookup. @param Polls86Adapter $legacy 8.6 signature. @param Polls9Adapter $modern 9.x signature. */
	public function __construct(private PollsNativeFactory $factory, private PollsMutationSnapshot $snapshots,
		private Polls86Adapter $legacy, private Polls9Adapter $modern) {}
	/** @param object $session Checked native session. @param array<string,mixed> $r Request. @param bool $nine Native signature line. @param \Closure $beforeSink Final reauthorization and attempted marker. @return void */
	public function write(object $session, array $r, bool $nine, \Closure $beforeSink): void {
		$tool = $r['tool']; $pollId = $r['poll_id'];
		if (isset($r['changes']) || isset($r['state'])) {
			$service = $this->factory->make(PollsSurface::POLLS, $session);
			$beforeSink();
			if (isset($r['changes'])) { $service->update($pollId, $r['changes']); }
			else { match ($r['state']) { 'closed' => $service->close($pollId), 'reopened' => $service->reopen($pollId),
				'archived', 'unarchived' => $service->toggleArchive($pollId) }; }
			return;
		}
		if (str_contains($tool, 'option')) {
			$service = $this->factory->make(PollsSurface::OPTIONS, $session);
			$simple = $tool === 'polls_add_option' ? \OCA\Polls\Model\SimpleOption::fromArray([
				'text' => $r['option']['text'] ?? '', 'timestamp' => $r['option']['timestamp'] ?? 0, 'duration' => $r['option']['duration'] ?? 0]) : null;
			$beforeSink();
			match ($tool) {
				'polls_add_option' => $service->add($pollId, $simple, false),
				'polls_update_option' => ($nine ? $this->modern : $this->legacy)->updateOption($service, $r),
				'polls_reorder_options' => $service->reorder($pollId, array_map(static fn (int $id): array => ['id' => $id], $r['option_ids'])),
				'polls_remove_option', 'polls_restore_option' => $service->delete($r['option_id'], $tool === 'polls_restore_option'),
			};
			return;
		}
		$service = $this->factory->make(PollsMutationSurface::SHARES, $session);
		if ($tool === 'polls_add_share') {
			$user = $r['type'] === 'public' ? null : $this->snapshots->user($session, $r, $nine);
			$beforeSink();
			($nine ? $this->modern : $this->legacy)->addShare($service, $r, $user);
			return;
		}
		// editPoll was proved before this lookup. Tokens remain local and never enter any plan/receipt/log.
		$share = null;
		foreach ($this->snapshots->shares($session, $pollId) as $candidate) {
			if ($candidate->getId() === $r['share_id']) { $share = $candidate; break; }
		}
		if ($share === null) { throw new ToolFailure(CommonMessages::notFound()); }
		$token = $share->getToken();
		if (!is_string($token) || $token === '') { throw PollsRefusal::unavailable(); }
		$beforeSink();
		if ($tool === 'polls_invite_share') {
			// 8.6 returns ?SentResult; 9.x returns SentResult. Neither is proof of persistence or delivery.
			$service->sendInvitation($share);
			return;
		}
		match ($r['action']) {
			'lock', 'unlock' => $service->lockByToken($token, $r['action'] === 'unlock'),
			'label' => $service->setLabel($r['value'], $token),
			'set_user', 'set_admin' => $service->setType($token, $r['action'] === 'set_admin' ? 'admin' : 'user'),
			'remove', 'restore' => $service->delete($share, $r['action'] === 'restore'),
		};
	}
}
