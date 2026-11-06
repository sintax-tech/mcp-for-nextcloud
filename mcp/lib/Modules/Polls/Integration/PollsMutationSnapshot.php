<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Modules\Polls\Writing\PollWriteRequest;
use OCA\Mcp\Modules\Polls\PollsRefusal;

/** Fresh complete edit-authorized metadata, including deleted children, without result or credential reads. */
final class PollsMutationSnapshot {
	/** @param PollsNativeFactory $factory Fresh service constructor. */
	public function __construct(private PollsNativeFactory $factory) {}
	/** @param object $session Authenticated global session. @param int $id Parent ID. @return object Native editor-authorized poll. */
	public function poll(object $session, int $id): object {
		try {
			$poll = $this->factory->make(PollsMutationSurface::POLL_MAPPER, $session)->get($id);
			if (!$poll instanceof \OCA\Polls\Db\Poll || $poll->getId() !== $id || !$poll->getIsAllowed('accessPoll')) {
				throw new ToolFailure(CommonMessages::notFound());
			}
		} catch (\Throwable $error) {
			if ($error instanceof ToolFailure || PollsCalls::missing($error)) { throw new ToolFailure(CommonMessages::notFound()); }
			throw $error;
		}
		if (!$poll->getIsAllowed('editPoll')) { throw new ToolFailure(CommonMessages::forbidden()); }
		return $poll;
	}
	/** @param object $session Checked native session. @param string $uid Actor. @param array<string,mixed> $r Request. @param string $version Installed version. @return array<string,mixed> Whitelist scalar snapshot. */
	public function read(object $session, string $uid, array $r, string $version): array {
		$p = $this->poll($session, $r['poll_id']);
		$s = ['actor' => $uid, 'version' => $version, 'poll' => ['id' => $p->getId(), 'title' => $p->getTitle(),
			'type' => $p->getType(), 'owner' => $p->getOwner(), 'access' => $p->getAccess(), 'anonymous' => $p->getAnonymous(),
			'expire' => $p->getExpire(), 'archived' => $p->getDeleted() > 0]];
		// deserializeArray invokes setAnonymousSafe even when anonymous was not supplied.
		if (isset($r['changes']) && $p->getAnonymous() > 0 && !$p->getIsAllowed('deanonymize')) {
			throw new ToolFailure(\OCA\Mcp\L10n\Translator::t('Updating this poll would lock anonymity, which this tool does not allow.'));
		}
		if (isset($r['changes'])) { $s['configuration'] = array_intersect_key(PollsWriteProjection::configuration($p), $r['changes']); }
		if (str_contains($r['tool'], 'option')) {
			if (isset($r['option']) && (($p->getType() === 'textPoll') !== isset($r['option']['text']))) { PollWriteRequest::invalid('option'); }
			$rows = $this->factory->make(PollsMutationSurface::OPTION_MAPPER, $session)->findByPoll($r['poll_id'], true, true);
			$s['options'] = [];
			foreach ($rows as $row) {
				if ($row->getPollId() !== $r['poll_id']) { throw PollsRefusal::unavailable(); }
				if (isset($r['option_id']) && $row->getId() !== $r['option_id']) { continue; }
				$s['options'][] = PollsWriteProjection::option($row);
			}
			usort($s['options'], static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
			if (isset($r['option'])) { $s['optionValue'] = $this->optionValue($r, $p, version_compare($version, '9.0.0', '>=')); }
			if ($r['tool'] === 'polls_update_option') {
				$s['optionCollision'] = false;
				foreach ($rows as $row) {
					$v = PollsWriteProjection::option($row);
					if ($v['id'] !== $r['option_id'] && array_intersect_key($v, $s['optionValue']) === $s['optionValue']) { $s['optionCollision'] = true; }
				}
			}
		}
		if (str_contains($r['tool'], 'share')) {
			$s['shares'] = [];
			foreach ($this->shares($session, $r['poll_id']) as $row) {
				if (isset($r['share_id']) && $row->getId() !== $r['share_id']) { continue; }
				$s['shares'][] = PollsWriteProjection::share($row);
			}
			usort($s['shares'], static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
			// AppSettings includes the logged-in actor's allowed group memberships and native default true.
			$s['publicAllowed'] = $this->factory->make(CreateSurface::SETTINGS, $session)->getPublicSharesAllowed() === true;
			$s['shareCreate'] = $p->getIsAllowed('shareCreate');
			$s['shareExternal'] = $p->getIsAllowed('shareCreateExternal');
			if ($r['tool'] === 'polls_add_share') {
				$s['recipient'] = $this->recipient($session, $r, version_compare($version, '9.0.0', '>='));
				$s['shares'] = array_values(array_filter($s['shares'], static fn (array $row): bool => $r['type'] === 'public'
					? $row['type'] === 'public' : $row['userId'] === $s['recipient']['userId']));
			}
		}
		foreach (['options', 'shares'] as $key) { if (count($s[$key] ?? []) > 200) { PollWriteRequest::invalid('arguments'); } }
		if (strlen(json_encode($s, JSON_THROW_ON_ERROR)) > 65536) { PollWriteRequest::invalid('arguments'); }
		return $s;
	}
	/** @param object $session Checked session. @param int $pollId Authorized parent. @return list<object> Only this poll's own shares, including deleted. */
	public function shares(object $session, int $pollId): array {
		$out = [];
		foreach ($this->factory->make(PollsMutationSurface::SHARE_MAPPER, $session)->findByPoll($pollId, [], true) as $share) {
			if (!$share instanceof \OCA\Polls\Db\Share || $share->getPollId() !== $pollId || $share->getGroupId()) { continue; }
			$out[] = $share;
		}
		return $out;
	}
	/** @param object $session Checked actor session. @param array<string,mixed> $r Typed recipient. @param bool $nine Native line. @return array<string,mixed> Safe canonical native recipient, without a token. */
	private function recipient(object $session, array $r, bool $nine): array {
		if ($r['type'] === 'public') { return ['type' => 'public', 'userId' => '']; }
		$user = $this->user($session, $r, $nine);
		$type = $nine ? $user->getType() : $user->getShareType();
		if (!in_array($r['type'], ['email', 'contact'], true) && $type !== $r['type']) { PollWriteRequest::invalid('recipient'); }
		if ($type === 'contact' || $type === 'email') {
			$email = $user->getEmailAddress();
			if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) { PollWriteRequest::invalid('recipient'); }
			return ['type' => 'email', 'userId' => $email];
		}
		if (in_array($r['type'], ['email', 'contact'], true)) { PollWriteRequest::invalid('recipient'); }
		return ['type' => $type, 'userId' => $user->getShareUserId()];
	}
	/** @param object $session Checked session. @param array<string,mixed> $r Validated input. @param bool $nine Native line. @return object Native typed recipient; no shares are created. */
	public function user(object $session, array $r, bool $nine): object {
		$v = $r['recipient'];
		$id = $v['user_id'] ?? $v['group_id'] ?? $v['email_address'] ?? $v['contact_id'];
		$c = PollsMutationSurface::USER_MAPPER;
		return $nine ? $c::createUserObject($r['type'], $id, $v['display_name'] ?? '', $v['email_address'] ?? '')
			: $this->factory->make($c, $session)->getUserObject($r['type'], $id, $v['display_name'] ?? '', $v['email_address'] ?? '');
	}
	/** @param array<string,mixed> $r Request. @param object $poll Authorized native poll. @param bool $nine Native line. @return array<string,mixed> Pure canonical option value. */
	private function optionValue(array $r, object $poll, bool $nine): array {
		$o = $r['option']; $text = $o['text'] ?? ''; $stamp = $o['timestamp'] ?? 0; $duration = $o['duration'] ?? 0;
		$value = new \OCA\Polls\Db\Option();
		if ($nine) { $value->setPoll($r['poll_id']); } else { $value->setPollId($r['poll_id']); }
		if ($r['tool'] === 'polls_add_option') {
			$simple = \OCA\Polls\Model\SimpleOption::fromArray(['text' => $text, 'timestamp' => $stamp, 'duration' => $duration]);
			if ($nine) { $value->setFromSimpleOption($simple, $poll->getType()); } else { $value->setFromSimpleOption($simple); }
		} elseif (!$nine) { $value->setOption($stamp, $duration, $text); }
		elseif ($poll->getType() === 'textPoll') { $value->setText($text); }
		else { $value->setDateTime(new \OCA\Polls\Model\DateTimeImmutable('@' . $stamp)); $value->setInterval(new \OCA\Polls\Model\DateInterval('PT' . $duration . 'S')); }
		return ['text' => $value->getPollOptionText(), 'timestamp' => $value->getTimestamp(), 'duration' => $value->getDuration()];
	}
}
