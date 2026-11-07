<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Contracts\PollWriteNative;
use OCA\Mcp\Modules\Polls\PollsRefusal;

/** Deterministic mutation port double; domain guard tests share this scene instead of ten copied suites. */
final class PollsMutationScene implements PollWriteNative {
	/** Sanitized native projection. */
	public array $state;
	/** Native effects attempted. */
	public int $writes = 0;
	/** Fresh snapshot count. */
	public int $reads = 0;
	/** Session cleanup count. */
	public int $closes = 0;
	/** Deny before any native access. */
	public bool $deny = false;
	/** Throw after persisting an effect. */
	public bool $throwAfter = false;
	/** Revoke observation after an attempted sink. */
	public bool $denyAfter = false;
	/** Cleanup failure after effects. */
	public bool $cleanupFails = false;
	/** Refuse immediately before the sink. */
	public bool $preflightFails = false;
	/** A reorder persists only its first item before failing. */
	public bool $partialReorder = false;
	/**
	 * @param string $tool Target tool; restore starts from a deleted child.
	 * @return void
	 */
	public function __construct(string $tool) {
		$this->state = [
			'actor' => 'alice',
			'version' => '9.3.0',
			'poll' => [
				'id' => 1,
				'title' => 'Poll <3',
				'type' => 'textPoll',
				'owner' => 'bob',
				'access' => 'private',
				'anonymous' => 0,
				'expire' => 0,
				'archived' => false,
			],
			'configuration' => ['title' => 'Poll <3', 'allowComment' => true],
			'options' => [
				[
					'text' => 'A',
					'timestamp' => 0,
					'duration' => 0,
					'id' => 10,
					'order' => 1,
					'deleted' => $tool === 'polls_restore_option',
					'owner' => '',
				],
				['text' => 'B', 'timestamp' => 0, 'duration' => 0, 'id' => 11, 'order' => 2, 'deleted' => false, 'owner' => ''],
			],
			'optionValue' => ['text' => 'New', 'timestamp' => 0, 'duration' => 0],
			'shares' => [
				[
					'id' => 20,
					'type' => 'user',
					'userId' => 'carol',
					'label' => '',
					'locked' => false,
					'deleted' => false,
					'invitationSent' => 0,
				],
			],
			'recipient' => ['type' => 'user', 'userId' => 'dave'],
			'publicAllowed' => true,
			'shareCreate' => true,
			'shareExternal' => true,
		];
	}

	/**
	 * {@inheritDoc}
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function open(string $uid, string $operation): void {
		if ($this->deny || $uid !== 'alice') {
			throw PollsRefusal::unavailable();
		}
	}

	/**
	 * {@inheritDoc}
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function snapshot(string $uid, array $request): array {
		$this->reads++;
		if ($this->deny || $this->denyAfter && $this->writes > 0) {
			throw PollsRefusal::unavailable();
		}
		return $this->state;
	}

	/**
	 * {@inheritDoc}
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function mutate(string $uid, array $request, array $before, \Closure $attempted): void {
		if ($this->preflightFails) {
			throw PollsRefusal::unavailable();
		}
		$attempted();
		$this->writes++;
		switch ($request['tool']) {
			case 'polls_update_poll':
				$this->state['configuration'] = array_replace($this->state['configuration'], $request['changes']);
				break;
			case 'polls_set_poll_state':
				$this->state['poll']['archived'] = true;
				break;
			case 'polls_add_option':
				$this->state['options'][] = [
					'text' => 'New',
					'timestamp' => 0,
					'duration' => 0,
					'id' => 12,
					'order' => 3,
					'deleted' => false,
					'owner' => 'alice',
				];
				break;
			case 'polls_update_option':
				$this->state['options'][0]['text'] = 'New';
				break;
			case 'polls_reorder_options':
				$this->state['options'][0]['order'] = 2;
				if (!$this->partialReorder) {
					$this->state['options'][1]['order'] = 1;
				}
				break;
			case 'polls_remove_option':
				$this->state['options'][0]['deleted'] = true;
				break;
			case 'polls_restore_option':
				$this->state['options'][0]['deleted'] = false;
				break;
			case 'polls_add_share':
				$this->state['shares'][] = [
					'id' => 21,
					'type' => 'user',
					'userId' => 'dave',
					'label' => '',
					'locked' => false,
					'deleted' => false,
					'invitationSent' => 0,
				];
				break;
			case 'polls_update_share':
				$this->state['shares'][0]['type'] = 'admin';
				break;
			case 'polls_invite_share':
				$this->state['shares'][0]['invitationSent'] = 1800000000;
				break;
		}
		if ($this->throwAfter) {
			throw new \RuntimeException('native token must never be echoed');
		}
	}

	/**
	 * {@inheritDoc}
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function close(): void {
		$this->closes++;
		if ($this->cleanupFails) {
			throw new \RuntimeException('cleanup failed');
		}
	}
}
