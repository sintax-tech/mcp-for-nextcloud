<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Reading;

use OCA\Mcp\Modules\Polls\Integration\PollsCalls;
use OCA\Mcp\Modules\Polls\Integration\PollsServices;
use OCA\Mcp\Modules\Polls\Integration\PollsSessionGuard;
use OCA\Mcp\Modules\Polls\Integration\PollsSurface;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Modules\Polls\Presentation\PollsShapes;
use OCA\Mcp\Modules\Polls\Presentation\PollsText;

/**
 * Native fetch-all candidates, ACCESS before paging for every actor including site admin, then at most limit+1 detail
 * reads. No refill when the window changes, no leaking discarded ids/counts, and no vote-dependent digest fields.
 */
final class PollsListing {
	/**
	 * Receives native services, guards, projection and MAC cursor.
	 * @param PollsServices $services native resolver
	 * @param PollsSessionGuard $guard both identity checks
	 * @param PollsShapes $shapes closed projection
	 * @param PollsCursor $cursor instance MAC
	 * @return void
	 */
	public function __construct(private PollsServices $services, private PollsSessionGuard $guard,
		private PollsShapes $shapes, private PollsCursor $cursor) {
	}

	/**
	 * Lists one reauthorized window inside PollsContext.
	 * @param array{limit:int,cursor:?string,id:?int} $request validated request
	 * @param string $uid actor
	 * @param object $session checked global UserSession
	 * @param string $version actual app version
	 * @return array<string,mixed> pure projected page
	 * @throws PollsRefusal for a changed window or failed native call
	 */
	public function page(array $request, string $uid, object $session, string $version): array {
		$this->guard->native($session, $uid);
		$service = $this->services->resolve(PollsSurface::POLLS);
		$this->guard->native($session, $uid);
		$candidates = $service->listPolls();
		if (!is_array($candidates)) {
			throw PollsRefusal::unavailable();
		}
		$keys = [];
		$seen = [];
		foreach ($candidates as $poll) {
			if (!is_object($poll) || !$poll instanceof (PollsSurface::POLL)) {
				throw PollsRefusal::unavailable();
			}
			$this->guard->native($session, $uid);
			if (!$poll->getIsAllowed(constant(PollsSurface::POLL . '::PERMISSION_POLL_ACCESS'))) {
				continue;
			}
			$key = $this->shapes->key($poll);
			if (isset($seen[$key['id']])) {
				throw PollsRefusal::unavailable();
			}
			$seen[$key['id']] = true;
			$keys[] = $key;
		}
		usort($keys, static fn (array $a, array $b): int => ($b['created'] <=> $a['created']) ?: ($b['id'] <=> $a['id']));
		$bound = ['version' => $version, 'limit' => $request['limit'], 'order' => 'created-desc,id-desc',
			'digest' => hash('sha256', json_encode($keys, JSON_THROW_ON_ERROR))];
		$offset = $this->cursor->offset($request['cursor'], 'polls_list_polls', $uid, $bound);
		$window = array_slice($keys, $offset, $request['limit'] + 1);
		$text = new PollsText();
		$rows = [];
		foreach ($window as $index => $key) {
			try {
				$this->guard->native($session, $uid);
				$poll = $service->get($key['id']);
			} catch (\Throwable $error) {
				if (PollsCalls::missing($error)) {
					throw PollsRefusal::changed();
				}
				throw $error;
			}
			$this->guard->native($session, $uid);
			if (!$poll->getIsAllowed(constant(PollsSurface::POLL . '::PERMISSION_POLL_ACCESS')) || $this->shapes->key($poll) !== $key) {
				throw PollsRefusal::changed();
			}
			if ($index < $request['limit']) {
				$this->guard->native($session, $uid);
				$rows[] = $this->shapes->poll($poll, $text, 'polls.' . $index);
			}
		}
		$more = count($window) > $request['limit'];
		$next = $more ? $this->cursor->next($offset + $request['limit'], 'polls_list_polls', $uid, $bound) : null;
		return ['polls' => $rows, 'hasMore' => $more, 'nextCursor' => $next, 'offsetLimitReached' => $more && $next === null] + $text->metadata();
	}
}
