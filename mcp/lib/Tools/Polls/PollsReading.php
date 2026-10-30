<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

/** Reads one poll and native filtered options; no direct mapper or adjacent content service is called. */
final class PollsReading {
	/**
	 * Receives native dependencies, guard, projection and MAC.
	 * @param PollsServices $services native resolver
	 * @param PollsSessionGuard $guard native identity checks
	 * @param PollsShapes $shapes response whitelist
	 * @param PollsCursor $cursor scope-bound cursor
	 * @return void
	 */
	public function __construct(private PollsServices $services, private PollsSessionGuard $guard,
		private PollsShapes $shapes, private PollsCursor $cursor) {
	}

	/**
	 * Reads one authorized poll and a bounded output page of its native visible options.
	 * @param array{limit:int,cursor:?string,id:?int} $request validated request
	 * @param string $uid actor
	 * @param object $session global native session
	 * @param string $version actual app version
	 * @return array<string,mixed> pure projected answer
	 * @throws PollsRefusal for absent/forbidden, stale cursor or unsupported native data
	 */
	public function page(array $request, string $uid, object $session, string $version): array {
		$this->guard->native($session, $uid);
		$service = $this->services->resolve(PollsSurface::POLLS);
		try {
			$this->guard->native($session, $uid);
			$poll = $service->get($request['id']);
		} catch (\Throwable $error) {
			if (PollsCalls::missing($error)) {
				throw PollsRefusal::missing();
			}
			throw $error;
		}
		$this->guard->native($session, $uid);
		if (!$poll->getIsAllowed(constant(PollsSurface::POLL . '::PERMISSION_POLL_ACCESS'))) {
			throw PollsRefusal::missing();
		}
		$this->guard->native($session, $uid);
		$options = $this->services->options($session);
		$this->guard->native($session, $uid);
		$native = $options->list($request['id']);
		$this->guard->native($session, $uid);
		$rows = $this->shapes->options($native, $request['id'], fn () => $this->guard->native($session, $uid));
		$this->guard->native($session, $uid);
		$text = new PollsText();
		$policy = null;
		$metadata = $this->shapes->poll($poll, $text, 'poll', $policy);
		// locked is vote-dependent: a vote reaching optionLimit may restart THIS cursor; no count is returned.
		$bound = ['version' => $version, 'poll' => $request['id'], 'limit' => $request['limit'], 'order' => 'order-asc,id-asc',
			'digest' => hash('sha256', json_encode([$metadata['access'], $policy, $rows], JSON_THROW_ON_ERROR))];
		$offset = $this->cursor->offset($request['cursor'], 'polls_read_poll', $uid, $bound);
		$window = array_slice($rows, $offset, $request['limit'] + 1);
		$more = count($window) > $request['limit'];
		$page = array_slice($window, 0, $request['limit']);
		foreach ($page as $index => &$row) {
			$row['text'] = $text->field($row['text'], 'options.' . $index . '.text');
		}
		unset($row);
		$next = $more ? $this->cursor->next($offset + $request['limit'], 'polls_read_poll', $uid, $bound) : null;
		$this->guard->native($session, $uid);
		return ['poll' => $metadata, 'options' => $page, 'hasMore' => $more, 'nextCursor' => $next,
			'offsetLimitReached' => $more && $next === null] + $text->metadata();
	}
}
