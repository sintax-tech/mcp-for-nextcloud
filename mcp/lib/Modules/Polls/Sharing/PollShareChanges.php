<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Sharing;

use OCA\Mcp\Modules\Polls\Writing\PollWriteRequest;
use OCA\Mcp\Modules\Polls\Options\OptionChanges;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteMessages;

/** Access changes and individual invitations; never expands a container or emits a credential. */
final class PollShareChanges {
	/** @param array<string,mixed> $r Request. @param array<string,mixed> $s Snapshot. @return array<string,mixed> Safe share effect. */
	public static function effect(array $r, array $s): array {
		$notes = [PollsWriteMessages::sharing()];
		if ($r['tool'] === 'polls_add_share') {
			if (!$s['shareCreate'] || (in_array($r['type'], ['public', 'email', 'contact'], true) && !$s['shareExternal'])
				|| ($r['type'] === 'public' && !$s['publicAllowed'])) { throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure(\OCA\Mcp\Platform\FileAccess\CommonMessages::forbidden()); }
			$v = $s['recipient'];
			$rows = $r['type'] === 'public' ? [] : array_values(array_filter($s['shares'], static fn (array $row): bool => $row['userId'] === $v['userId']));
			$old = $rows[0] ?? null;
			if (count($rows) > 1 || ($old !== null && ($old['deleted'] || $old['type'] !== $v['type']))) { PollWriteRequest::invalid('recipient'); }
			if ($r['type'] === 'admin') { $notes[] = PollsWriteMessages::delegationTo($v['userId']); }
			return ['before' => $old, 'after' => $v + ['id' => $old['id'] ?? null, 'deleted' => false], 'noop' => $old !== null, 'notes' => $notes];
		}
		$old = OptionChanges::find($s['shares'], $r['share_id']);
		if ($r['tool'] === 'polls_invite_share') {
			if (!in_array($old['type'], ['user', 'admin', 'email', 'contact'], true) || $old['deleted'] || $old['locked']) { PollWriteRequest::invalid('share_id'); }
			return ['before' => $old, 'after' => ['id' => $old['id'], 'invitationAttempt' => true], 'noop' => false, 'notes' => [...$notes, PollsWriteMessages::invitation()]];
		}
		$a = $r['action'];
		if ($a === 'label' && $old['type'] !== 'public') { PollWriteRequest::invalid('action'); }
		if ($a === 'restore' && $old['type'] === 'public' && (!$s['publicAllowed'] || !$s['shareCreate'] || !$s['shareExternal'])) {
			throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure(\OCA\Mcp\Platform\FileAccess\CommonMessages::forbidden());
		}
		if ($old['deleted'] && !in_array($a, ['restore', 'remove'], true)) { PollWriteRequest::invalid('action'); }
		if (in_array($a, ['set_user', 'set_admin'], true) && !in_array($old['type'], ['user', 'admin'], true)) { PollWriteRequest::invalid('action'); }
		$patch = match ($a) {
			'lock', 'unlock' => ['locked' => $a === 'lock'],
			'remove', 'restore' => ['deleted' => $a === 'remove'],
			'set_user', 'set_admin' => ['type' => $a === 'set_admin' ? 'admin' : 'user'],
			'label' => ['label' => $r['value']],
		};
		if ($a === 'set_admin') { $notes[] = PollsWriteMessages::delegationTo($old['userId']); }
		$after = array_replace($old, $patch);
		$noop = $old === $after;
		if (in_array($old['type'], ['user', 'admin'], true)) {
			if (in_array($a, ['lock', 'remove'], true)) { $after['type'] = 'user'; }
			if (in_array($a, ['unlock', 'restore'], true)) {
				$after['type'] = ['user', 'admin'];
				$notes[] = PollsWriteMessages::delegation();
			}
		}
		return ['before' => $old, 'after' => $noop ? $old : $after, 'noop' => $noop, 'notes' => $notes];
	}
	/** @param array<string,mixed> $r Request. @param array<string,mixed> $before Initial state. @param array<string,mixed> $now Readback. @return bool Requested effect observed; never email delivery. */
	public static function matches(array $r, array $before, array $now): bool {
		if ($r['tool'] === 'polls_invite_share') {
			$old = OptionChanges::find($before['shares'], $r['share_id']);
			$new = OptionChanges::find($now['shares'], $r['share_id']);
			return $new['invitationSent'] > $old['invitationSent'] && $new['invitationSent'] > 0;
		}
		$after = self::effect($r, $before)['after'];
		$rows = $now['shares'];
		if ($r['tool'] === 'polls_add_share') {
			$ids = array_column($before['shares'], 'id');
			$rows = array_values(array_filter($rows, static fn (array $row): bool => $after['id'] !== null ? $row['id'] === $after['id']
				: (!in_array($row['id'], $ids, true) && $row['type'] === $after['type'] && ($r['type'] === 'public' || $row['userId'] === $after['userId']))));
		} else { $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] === $r['share_id'])); }
		if (count($rows) !== 1) { return false; }
		foreach ($after as $key => $value) {
			if ($key === 'type' && is_array($value)) { if (!in_array($rows[0][$key], $value, true)) { return false; } }
			elseif ($value !== null && $rows[0][$key] !== $value) { return false; }
		}
		return true;
	}
}
