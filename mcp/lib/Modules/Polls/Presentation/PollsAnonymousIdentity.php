<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

use OCA\Mcp\L10n\Translator;
use OCP\IConfig;
use OCA\Mcp\Modules\Polls\PollsRefusal;

/** Reduces only identities the logged-in native serializer actually classified as anonymous. */
final class PollsAnonymousIdentity {
	/**
	 * Receives the instance secret source; no public fallback or parallel identity lookup exists.
	 * @param IConfig $config system configuration
	 * @return void
	 */
	public function __construct(private IConfig $config) {
	}

	/**
	 * Uses an unambiguous, separate domain and poll id; raw native hashes/names never enter the result.
	 * @param int $pollId authorized poll
	 * @param string $nativeId native anonymous id, transient input only
	 * @return array{id:string,type:string,displayName:string} reduced identity
	 * @throws PollsRefusal for an empty secret or invalid native id
	 */
	public function of(int $pollId, string $nativeId): array {
		$secret = $this->config->getSystemValueString('secret', '');
		if ($secret === '' || $pollId < 1 || $nativeId === '' || !mb_check_encoding($nativeId, 'UTF-8')) {
			throw PollsRefusal::unavailable();
		}
		$message = json_encode(['mcp.polls.anonymous-identity.v1', $pollId, $nativeId], JSON_THROW_ON_ERROR);
		return ['id' => 'anon1.' . hash_hmac('sha256', $message, $secret), 'type' => 'anonymous', 'displayName' => Translator::t('Anonymous')];
	}
}
