<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Writing\PollWriteRequest;
use OCA\Mcp\Modules\Polls\Editing\PollConfiguration;
use OCA\Mcp\Modules\Polls\Options\OptionChanges;
use OCA\Mcp\Modules\Polls\Sharing\PollShareChanges;
use OCA\Mcp\Modules\Polls\Integration\PollsWriteProjection;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Specific option, state and access cases complement the shared guard data provider. */
final class PollsMutationRulesTest extends TestCase {
	/** @return iterable<string,array{array<string,mixed>}> Invalid closed conditional inputs. */
	public static function invalidInputs(): iterable {
		yield 'open' => [['poll_id' => 1, 'changes' => ['access' => 'open']]];
		yield 'empty patch' => [['poll_id' => 1, 'changes' => []]];
		yield 'boolean proposals' => [['poll_id' => 1, 'changes' => ['allowProposals' => true]]];
		yield 'negative expire' => [['poll_id' => 1, 'changes' => ['expire' => -1]]];
		yield 'empty title' => [['poll_id' => 1, 'changes' => ['title' => '   ']]];
		yield 'numeric id string' => [['poll_id' => '1', 'changes' => ['title' => 'A']]];
		yield 'locked anonymity' => [['poll_id' => 1, 'changes' => ['anonymous' => -1]]];
	}
	/** @param array<string,mixed> $args Invalid request. @return void */
	#[DataProvider('invalidInputs')]
	public function testConfigurationInputIsClosed(array $args): void {
		$this->expectException(ArgumentValidationException::class);
		PollWriteRequest::of('polls_update_poll', $args);
	}
	/** @return void Desired-state no-op and future expiration removal are distinct. */
	public function testDesiredStateDoesNotToggleOrLeaveFutureExpiration(): void {
		$s = new PollsMutationScene('polls_set_poll_state'); $flow = PollsMutationCases::flow($this, $s);
		$s->state['poll']['archived'] = true;
		$a = ['poll_id' => 1, 'state' => 'archived']; $p = $flow->run('polls_set_poll_state', $a, 'alice', true);
		$out = $flow->run('polls_set_poll_state', $a + ['confirm' => true, 'plan_state' => $p['plan_state']], 'alice', false);
		self::assertTrue($out['noOp']); self::assertFalse($out['nativeCallAttempted']); self::assertSame(0, $s->writes);
		$s->state['poll']['expire'] = time() + 3600;
		self::assertFalse(PollConfiguration::effect(['state' => 'reopened'], $s->state)['noop']);
	}
	/** @return void Deleted collision restores the original owner and warns about preserved votes. */
	public function testDeletedCollisionAndDelegatedOptionOwnership(): void {
		$s = (new PollsMutationScene('polls_add_option'))->state;
		$s['optionValue'] = ['text' => 'A', 'timestamp' => 0, 'duration' => 0]; $s['options'][0]['deleted'] = true;
		$e = OptionChanges::effect(['tool' => 'polls_add_option', 'option' => ['text' => 'A']], $s);
		self::assertSame(10, $e['after']['id']); self::assertSame('', $e['after']['owner']); self::assertFalse($e['noop']);
		self::assertStringContainsString('votes may reappear', implode(' ', $e['notes']));
		$s['optionValue']['text'] = 'New';
		self::assertSame('alice', OptionChanges::effect(['tool' => 'polls_add_option', 'option' => ['text' => 'New']], $s)['after']['owner']);
		$s['version'] = '8.6.3'; $s['poll']['type'] = 'datePoll';
		self::assertNull(OptionChanges::effect(['tool' => 'polls_add_option', 'option' => ['timestamp' => 1800000000]], $s)['after']['order']);
	}
	/** @return void Wrong child ID has the common absence answer; never exposes another poll. */
	public function testForeignOptionAndShareAreNotFound(): void {
		foreach (['polls_update_option', 'polls_invite_share'] as $tool) {
			$s = new PollsMutationScene($tool); $flow = PollsMutationCases::flow($this, $s);
			$args = $tool === 'polls_update_option' ? ['poll_id' => 1, 'option_id' => 999, 'option' => ['text' => 'New']] : ['poll_id' => 1, 'share_id' => 999];
			try { $flow->run($tool, $args, 'alice', true); self::fail('foreign ID accepted'); }
			catch (ToolFailure $e) { self::assertSame(CommonMessages::notFound(), $e->getMessage()); self::assertSame(0, $s->writes); }
		}
	}
	/** @return void Complete list and uniform nonexpired text-only ordering. */
	public function testReorderCompletenessAndExpiration(): void {
		$s = new PollsMutationScene('polls_reorder_options');
		foreach ([[10], [10, 10], [10, 999]] as $ids) {
			try { $a = PollWriteRequest::of('polls_reorder_options', ['poll_id' => 1, 'option_ids' => $ids]); OptionChanges::effect($a, $s->state); self::fail('invalid list accepted'); }
			catch (ArgumentValidationException) { self::assertSame(0, $s->writes); }
		}
		$s->state['version'] = '8.6.3'; $s->state['poll']['expire'] = time() - 10;
		$this->expectException(ArgumentValidationException::class);
		OptionChanges::effect(['tool' => 'polls_reorder_options', 'option_ids' => [11, 10]], $s->state);
	}
	/** @return void Effective public policy and separate external sharing permission are required. */
	public function testPublicPolicyAndDelegation(): void {
		$s = (new PollsMutationScene('polls_add_share'))->state; $s['recipient'] = ['type' => 'public', 'userId' => ''];
		$r = ['tool' => 'polls_add_share', 'type' => 'public'];
		foreach (['publicAllowed', 'shareCreate', 'shareExternal'] as $key) {
			$denied = array_replace($s, [$key => false]);
			try { PollShareChanges::effect($r, $denied); self::fail('missing guard accepted'); }
			catch (ToolFailure) { self::assertTrue($s[$key]); }
		}
		self::assertFalse(PollShareChanges::effect($r, $s)['noop']);
		$e = PollShareChanges::effect(['tool' => 'polls_update_share', 'share_id' => 20, 'action' => 'set_admin'], $s);
		self::assertStringContainsString('editing rights', implode(' ', $e['notes']));
	}
	/** @return void No invitation of a group/public share and no label mutation on an individual share. */
	public function testInvitationAndLabelTypesAreRestricted(): void {
		foreach (['group', 'public'] as $type) {
			$s = (new PollsMutationScene('polls_invite_share'))->state; $s['shares'][0]['type'] = $type;
			try { PollShareChanges::effect(['tool' => 'polls_invite_share', 'share_id' => 20], $s); self::fail('container invited'); }
			catch (ArgumentValidationException) { self::assertTrue(true); }
		}
		$this->expectException(ArgumentValidationException::class);
		PollShareChanges::effect(['tool' => 'polls_update_share', 'share_id' => 20, 'action' => 'label', 'value' => 'X'], (new PollsMutationScene('polls_update_share'))->state);
	}
	/** @return void Native 0/1 and booleans compare equally; arbitrary truthy data does not. */
	public function testBooleanReadbackNormalizationIsStrict(): void {
		$p = new class { public array $c = []; public function getConfigurationArray(): array { return $this->c; } };
		foreach ([0 => false, 1 => true] as $native => $expected) {
			$p->c = ['allowComment' => $native, 'allowMaybe' => $expected, 'token' => 'never projected'];
			self::assertSame(['allowComment' => $expected, 'allowMaybe' => $expected], PollsWriteProjection::configuration($p));
		}
		$p->c = ['allowComment' => 2];
		$this->expectException(ToolFailure::class); PollsWriteProjection::configuration($p);
	}
	/** @return void Partly persisted reorder is observed and never resumed automatically. */
	public function testReorderPartialReceiptIsBasedOnObservedRequestedPositions(): void {
		$s = new PollsMutationScene('polls_reorder_options'); $flow = PollsMutationCases::flow($this, $s);
		$a = ['poll_id' => 1, 'option_ids' => [11, 10]]; $p = $flow->run('polls_reorder_options', $a, 'alice', true);
		$s->partialReorder = $s->throwAfter = true;
		$out = $flow->run('polls_reorder_options', $a + ['confirm' => true, 'plan_state' => $p['plan_state']], 'alice', false);
		self::assertSame('partial', $out['status']); self::assertSame(1, $s->writes);
		self::assertSame([2, 2], array_column($out['observed']['options'], 'order'));
	}
	/** @return void Date readback uses native observable timestamp order in both signature lines. */
	public function testUpdatedDateOrderFollowsItsNewTimestamp(): void {
		$s = (new PollsMutationScene('polls_update_option'))->state; $s['poll']['type'] = 'datePoll';
		$s['optionValue'] = ['text' => 'Date', 'timestamp' => 1800000000, 'duration' => 3600];
		$r = ['tool' => 'polls_update_option', 'option_id' => 10, 'option' => ['timestamp' => 1800000000, 'duration' => 3600]];
		$after = OptionChanges::effect($r, $s)['after'];
		self::assertSame(1800000000, $after['order']);
		$now = $s; $now['options'][0] = $after;
		self::assertTrue(OptionChanges::matches($r, $s, $now));
	}
	/** @return void Observed side effects on protected configuration are never reported as complete. */
	public function testConfigurationReadbackChecksProtectedFields(): void {
		$s = (new PollsMutationScene('polls_update_poll'))->state; $now = $s;
		$now['configuration']['title'] = 'New'; $now['poll']['anonymous'] = -1;
		self::assertFalse(PollConfiguration::matches(['changes' => ['title' => 'New']], $s, $now));
	}
}
