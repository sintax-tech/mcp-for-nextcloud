<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Editing\PollConfiguration;
use OCA\Mcp\Modules\Polls\Sharing\PollShareChanges;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteMessages;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** State and share regressions found by independent mutations. */
final class PollsMutationDomainGuardsTest extends TestCase {
	/**
	 * @return iterable<string,array{string}> Each effective public sharing guard.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function publicGuards(): iterable {
		foreach (['publicAllowed', 'shareCreate', 'shareExternal'] as $key) {
			yield $key => [$key];
		}
	}

	/**
	 * @param string $key Only this effective policy denies restoring a public share.
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	#[DataProvider('publicGuards')]
	public function testRestoringPublicShareRechecksEveryPolicy(string $key): void {
		$s = (new PollsMutationScene('polls_update_share'))->state;
		$s['shares'][0]['type'] = 'public';
		$s['shares'][0]['deleted'] = true;
		$s[$key] = false;
		$this->expectException(ToolFailure::class);
		PollShareChanges::effect(['tool' => 'polls_update_share', 'share_id' => 20, 'action' => 'restore'], $s);
	}

	/**
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testInvitationOfLockedIndividualIsRefused(): void {
		$s = (new PollsMutationScene('polls_invite_share'))->state;
		$s['shares'][0]['locked'] = true;
		$this->expectException(ArgumentValidationException::class);
		PollShareChanges::effect(['tool' => 'polls_invite_share', 'share_id' => 20], $s);
	}

	/**
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testUnarchiveOfActivePollDoesNotCallToggle(): void {
		$s = new PollsMutationScene('polls_set_poll_state');
		$flow = PollsMutationCases::flow($this, $s);
		$a = ['poll_id' => 1, 'state' => 'unarchived'];
		$p = $flow->run('polls_set_poll_state', $a, 'alice', true);
		$out = $flow->run('polls_set_poll_state', $a + ['confirm' => true, 'plan_state' => $p['plan_state']], 'alice', false);
		self::assertTrue($out['noOp']);
		self::assertFalse($out['nativeCallAttempted']);
		self::assertSame(0, $s->writes);
		self::assertFalse($s->state['poll']['archived']);
	}

	/**
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testScheduledExpirationDoesNotCountAsAlreadyClosed(): void {
		$s = (new PollsMutationScene('polls_set_poll_state'))->state;
		$s['poll']['expire'] = time() + 3600;
		self::assertFalse(PollConfiguration::effect(['state' => 'closed'], $s)['noop']);
		$s['poll']['expire'] = time() - 3600;
		self::assertTrue(PollConfiguration::effect(['state' => 'closed'], $s)['noop']);
	}

	/**
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testAddingAdminNamesTheDelegatedRecipientAndRights(): void {
		$s = (new PollsMutationScene('polls_add_share'))->state;
		$s['recipient'] = ['type' => 'admin', 'userId' => 'named-editor'];
		$effect = PollShareChanges::effect(['tool' => 'polls_add_share', 'type' => 'admin'], $s);
		self::assertContains(PollsWriteMessages::delegationTo('named-editor'), $effect['notes']);
		self::assertStringContainsString('editing rights', implode(' ', $effect['notes']));
	}

	/**
	 * @return iterable<string,array{string,array<string,mixed>}> Every handler direct call.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function writes(): iterable {
		return PollsMutationCases::all();
	}

	/**
	 * @param string $tool Exact mutation.
	 * @param array<string,mixed> $a Valid request.
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	#[DataProvider('writes')]
	public function testDirectEffectRequiresStrictBooleanConfirm(string $tool, array $a): void {
		$s = new PollsMutationScene($tool);
		$flow = PollsMutationCases::flow($this, $s);
		$p = $flow->run($tool, $a, 'alice', true);
		foreach ([null, false, 0, 1, 'true', '1'] as $confirm) {
			try {
				$flow->run($tool, $a + ['confirm' => $confirm, 'plan_state' => $p['plan_state']], 'alice', false);
				self::fail('Nonboolean confirmation reached the effect');
			} catch (ToolFailure|\InvalidArgumentException) {
				self::assertSame(0, $s->writes);
			}
		}
	}
}
