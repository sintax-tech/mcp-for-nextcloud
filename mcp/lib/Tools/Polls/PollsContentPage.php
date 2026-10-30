<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

/** Coordinates native reads, policy revalidation and offset-only MAC pagination for PO-2. */
final class PollsContentPage {
	/**
	 * Receives existing context dependencies and focused operation projections.
	 * @param PollsServices $services native resolver/fresh options factory
	 * @param PollsSessionGuard $guard session identity checks
	 * @param PollsContentSurface $surface extra public signatures
	 * @param PollsContentPolicy $policy fresh ACCESS/effective state
	 * @param PollsVoteShapes $votes authorized/reduced vote shape
	 * @param PollsResultShapes $results guarded native aggregates
	 * @param PollsLinkShapes $links editor-only existing URLs
	 * @param PollsCursor $cursor existing offset MAC
	 * @return void
	 */
	public function __construct(private PollsServices $services, private PollsSessionGuard $guard,
		private PollsContentSurface $surface, private PollsContentPolicy $policy, private PollsVoteShapes $votes,
		private PollsResultShapes $results, private PollsLinkShapes $links, private PollsCursor $cursor) {
	}

	/**
	 * Collects only through native services and projects before cleanup; no token request/registration exists here.
	 * Three explicit fresh Poll gets bound observed state, without claiming detection of every concurrent ABA.
	 * @param string $kind votes, results or links
	 * @param string $tool exact tool for cursor domain
	 * @param array{id:int,limit:int,cursor:?string,option:?int} $request validated query
	 * @param string $uid actor
	 * @param object $session global native session
	 * @param string $version installed version
	 * @return array<string,mixed> pure authorized output
	 * @throws PollsRefusal on access, identity, shape or observed-state divergence
	 */
	public function read(string $kind, string $tool, array $request, string $uid, object $session, string $version): array {
		$assert = fn () => $this->guard->native($session, $uid);
		$assert();
		$this->surface->assertSupported($kind);
		$state = $this->policy->read($request['id'], $uid, $session, $kind === 'links');
		$all = $state['results'];
		$assert();
		$rows = [];
		if ($kind !== 'results' || $all) {
			$service = match ($kind) {
				'votes' => $this->services->resolve(PollsContentSurface::VOTES),
				'links' => $this->services->resolve(PollsContentSurface::SHARES),
				'results' => $this->services->options($session),
				default => throw PollsRefusal::unavailable(),
			};
			$assert();
			$rows = $kind === 'links' ? $service->list($request['id'], 'poll') : $service->list($request['id']);
		}
		$assert();
		if ($state !== $this->policy->read($request['id'], $uid, $session, $kind === 'links')) {
			throw PollsRefusal::changed();
		}
		$projected = match ($kind) {
			'votes' => $this->votes->project($rows, $request['id'], $all, $assert),
			'results' => $all ? $this->results->project($rows, $request['id'], $assert) : [],
			'links' => $this->links->project($rows, $request['id'], $state['groups'], $assert),
		};
		if ($kind === 'votes' && $request['option'] !== null) {
			$projected = array_values(array_filter($projected, static fn (array $row): bool => $row['optionId'] === $request['option']));
		}
		$scope = $all ? 'all' : 'own';
		$bound = ['version' => $version, 'poll' => $request['id'], 'limit' => $request['limit'], 'option' => $request['option'],
			'order' => match ($kind) {'votes' => 'id-asc,optionId-asc', 'results' => 'order-asc,id-asc', 'links' => 'id-asc'},
			'scope' => $kind === 'links' ? 'edit' : $scope, 'policy' => $state,
			'digest' => hash('sha256', json_encode($projected, JSON_THROW_ON_ERROR))];
		$offset = $this->cursor->offset($request['cursor'], $tool, $uid, $bound);
		$window = array_slice($projected, $offset, $request['limit'] + 1);
		$more = count($window) > $request['limit'];
		$page = array_slice($window, 0, $request['limit']);
		$text = new PollsText();
		foreach ($page as $index => &$row) {
			if ($kind === 'votes') {
				$row['optionText'] = $text->field($row['optionText'], 'votes.' . $index . '.optionText');
				$row['user']['displayName'] = $text->field($row['user']['displayName'], 'votes.' . $index . '.user.displayName');
			} elseif ($kind === 'results') {
				$row['text'] = $text->field($row['text'], 'options.' . $index . '.text');
			}
		}
		unset($row);
		$next = $more ? $this->cursor->next($offset + $request['limit'], $tool, $uid, $bound) : null;
		if ($state !== $this->policy->read($request['id'], $uid, $session, $kind === 'links')) {
			throw PollsRefusal::changed();
		}
		$assert();
		$data = match ($kind) {
			'votes' => ['votes' => $page, 'scope' => $scope, 'resultsVisible' => $all],
			'results' => ['options' => $all ? $page : null, 'scope' => $scope, 'resultsVisible' => $all],
			'links' => ['links' => $page, 'coverage' => PollsLinkShapes::COVERAGE, 'warning' => PollsContentMessages::LINKS],
		};
		return ['pollId' => $request['id']] + $data + ['hasMore' => $more, 'nextCursor' => $next,
			'offsetLimitReached' => $more && $next === null, 'limits' => PollsContentMessages::COST] + $text->metadata();
	}
}
