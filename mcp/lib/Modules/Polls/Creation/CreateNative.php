<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Polls\Creation;

use OCA\Mcp\Modules\Polls\Admission\CreateAdmission;
use OCA\Mcp\Modules\Polls\Integration\CreateSurface;
use OCA\Mcp\Modules\Polls\Integration\PollsServices;
use OCA\Mcp\Modules\Polls\Integration\PollsSessionGuard;
use OCA\Mcp\Modules\Polls\Integration\PollsSurface;
use OCA\Mcp\Modules\Polls\PollsRefusal;
/** Fresh native service construction and guard-before-call boundary; no cache/property mutation. */
class CreateNative {
	/**
	 * Receives existing resolver/session checks and focused admission/surface gates.
	 * @param PollsServices $services existing dependency/global session source
	 * @param PollsSessionGuard $guard both native identities and endpoint identity
	 * @param CreateAdmission $admission independent create grant
	 * @param CreateSurface $surface exact public write surfaces
	 * @return void
	 */
	public function __construct(private PollsServices $services, private PollsSessionGuard $guard, private CreateAdmission $admission, private CreateSurface $surface) {
	}
	/**
	 * Admits before opening the checked global native session.
	 * @param string $uid authenticated actor
	 * @return array{object,string,bool} session, version and native layout
	 */
	public function open(string $uid): array {
		$this->guard->caller($uid);
		$version = $this->admission->check($uid);
		$nine = $this->surface->check();
		return [$this->services->session(), $version, $nine];
	}
	/**
	 * Revalidates context/grant and the effective native policy; no live-cache freshness is promised.
	 * @param object $session native global session
	 * @param string $uid actor
	 * @return void
	 * @throws PollsRefusal for revoked or divergent state
	 */
	public function check(object $session, string $uid): void {
		$this->guard->native($session, $uid);
		$this->admission->check($uid);
		if ($this->services->resolve(CreateSurface::SETTINGS)->getPollCreationAllowed() !== true) {
			throw PollsRefusal::unavailable();
		}
		$this->guard->native($session, $uid);
	}
	/**
	 * Rechecks only identity/grant when deciding whether a partial receipt is still authorized.
	 * @param object $session native global session
	 * @param string $uid actor
	 * @return void
	 */
	public function receiptAccess(object $session, string $uid): void {
		$this->guard->native($session, $uid);
		$this->admission->check($uid);
	}
	/**
	 * Constructs a native PollService with its real ordered dependencies and a fresh Poll.
	 * @param object $session checked native global session
	 * @return object new native PollService
	 */
	public function polls(object $session): object {
		$pollClass = PollsSurface::POLL;
		$args = [];
		foreach (CreateSurface::DEPS as $class) {
			$args[] = match ($class) {
				PollsSurface::SESSION => $session,
				PollsSurface::POLL => new $pollClass(),
				default => $this->services->resolve($class),
			};
		}
		$class = PollsSurface::POLLS;
		return new $class(...$args);
	}
	/**
	 * Creates a fresh native options service for one stage, not the previous stage's cached Poll.
	 * @param object $session native global session
	 * @return object native options service
	 */
	public function options(object $session): object {
		return $this->services->options($session);
	}
	/**
	 * Authorizes a fresh public readback of only the newly returned ID.
	 * @param int $id trusted returned poll id
	 * @param array<string,mixed> $request immutable input
	 * @param object $session checked session
	 * @param string $uid actor/new owner
	 * @param bool $nine native layout
	 * @return object authorized poll whose observable state matches the plan
	 * @throws PollsRefusal for any observable divergence
	 */
	public function readback(int $id, array $request, object $session, string $uid, bool $nine): object {
		$this->check($session, $uid);
		return $this->readChecked($id, $request, $session, $uid, $nine);
	}
	/**
	 * Reads a retained receipt without requiring permission to start another creation.
	 * @param int $id trusted returned poll id
	 * @param array<string,mixed> $request immutable input
	 * @param object $session checked session
	 * @param string $uid actor/new owner
	 * @param bool $nine native layout
	 * @return object authorized poll matching every receipt integrity condition
	 * @throws PollsRefusal for revoked grant/context/ACL or observable divergence
	 */
	public function receiptReadback(int $id, array $request, object $session, string $uid, bool $nine): object {
		$this->receiptAccess($session, $uid);
		return $this->readChecked($id, $request, $session, $uid, $nine);
	}
	/**
	 * Applies the shared fresh-readback integrity checks after the caller's admission.
	 * @param int $id trusted returned poll id
	 * @param array<string,mixed> $request immutable input
	 * @param object $session checked session
	 * @param string $uid actor/new owner
	 * @param bool $nine native layout
	 * @return object authorized poll matching the announced observable state
	 * @throws PollsRefusal for any observable divergence
	 */
	private function readChecked(int $id, array $request, object $session, string $uid, bool $nine): object {
		$poll = $this->polls($session)->get($id);
		$class = PollsSurface::POLL;
		$this->guard->native($session, $uid);
		if (!$poll instanceof $class
			|| $poll->getId() !== $id
			|| $poll->getOwner() !== $uid
			|| !$poll->getIsAllowed(constant($class . '::PERMISSION_POLL_ACCESS'))
			|| !$poll->getIsAllowed(constant($class . '::PERMISSION_POLL_EDIT'))
			|| !$poll->getIsAllowed(constant($class . '::PERMISSION_OPTION_ADD'))
			|| $poll->getType() !== $request['type']
			|| $poll->getTitle() !== $request['title']
			|| CreateDefaults::read($poll, $nine) !== CreateDefaults::observable($nine)
			|| !is_int($poll->getCreated())
			|| $poll->getCreated() < 1
			|| !is_int($poll->getLastInteraction())
			|| $poll->getLastInteraction() < 1) {
			throw PollsRefusal::unavailable();
		}
		$this->guard->native($session, $uid);
		return $poll;
	}
}
