<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

use OCA\Mcp\Modules\Polls\PollsRefusal;

/** Gates additional public read surfaces only; future matching signatures do not prove future semantics. */
final class PollsContentSurface {
	/** Native vote service. */
	public const VOTES = 'OCA\\Polls\\Service\\VoteService';
	/** Native share service, list only. */
	public const SHARES = 'OCA\\Polls\\Service\\ShareService';
	/** Native anonymous type, not an extra identity lookup. */
	public const ANON = 'OCA\\Polls\\Model\\User\\Anon';
	/** Native vote entity. */
	public const VOTE = 'OCA\\Polls\\Db\\Vote';
	/** Native share entity. */
	public const SHARE = 'OCA\\Polls\\Db\\Share';
	/**
	 * Checks required signatures/constants for this operation before collecting protected data.
	 * Dynamic getters are validated by actual returned types, not method_exists.
	 * @param string $kind votes, results or links
	 * @throws PollsRefusal for unsupported surfaces
	 */
	public function assertSupported(string $kind): void {
		$surface = new PollsSurface();
		foreach (['PERMISSION_POLL_EDIT', 'PERMISSION_POLL_RESULTS_VIEW'] as $constant) {
			if (!defined(PollsSurface::POLL . '::' . $constant) || !is_string(constant(PollsSurface::POLL . '::' . $constant))) {
				throw PollsRefusal::unavailable();
			}
		}
		if ($kind === 'votes') {
			$surface->assertMethod(self::VOTES, 'list', ['int'], 'array');
			$surface->assertMethod(self::VOTE, 'jsonSerialize', [], 'array');
			$surface->assertMethod(self::VOTE, 'getCurrentUserIsEntityUser', [], 'bool');
			if (!defined(self::ANON . '::TYPE') || constant(self::ANON . '::TYPE') !== 'anonymous') {
				throw PollsRefusal::unavailable();
			}
		} elseif ($kind === 'links') {
			$surface->assertMethod(self::SHARES, 'list', ['int', 'string'], 'array');
			$surface->assertMethod(self::SHARE, 'getURL', [], 'string');
			$surface->assertMethod(self::SHARE, 'getType', [], 'string');
			foreach (['USER' => 'user', 'ADMIN' => 'admin', 'GROUP' => 'group', 'PUBLIC' => 'public', 'EMAIL' => 'email',
				'CONTACT' => 'contact', 'EXTERNAL' => 'external', 'CONTACTGROUP' => 'contactGroup'] as $key => $value) {
				if (!defined(self::SHARE . '::TYPE_' . $key) || constant(self::SHARE . '::TYPE_' . $key) !== $value) {
					throw PollsRefusal::unavailable();
				}
			}
			if ((!defined(self::SHARE . '::TYPE_CIRCLE') || constant(self::SHARE . '::TYPE_CIRCLE') !== 'circle')
				&& (!defined(self::SHARE . '::TYPE_TEAM') || constant(self::SHARE . '::TYPE_TEAM') !== 'circle')) {
				throw PollsRefusal::unavailable();
			}
		}
	}
}
