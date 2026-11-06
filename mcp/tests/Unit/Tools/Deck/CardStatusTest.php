<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Modules\Deck\Editing\{CardStatusPlan, CardStatusResult, SetCardDoneHandler, SetCardArchivedHandler};
use OCA\Mcp\Modules\Deck\Integration\{CardStatusGatewayInterface, DeckGatewayInterface};
use OCA\Mcp\Modules\Deck\Presentation\DeckPlans\CardStatusText;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Mcp\Confirmation\{PlanState, PlanChanged};
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/** Status confirmation, safe replay and the two-stage native completion outcome. */
final class CardStatusTest extends TestCase {
	use DeckTestHelpers;

	/** @return PlanState Test-specific immutable secret, no production service lookup. */
	private function signer(): PlanState {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('status-test-secret');
		return new PlanState($config);
	}

	/** @return iterable<string,array{string,bool,class-string,string}> The four explicitly supported directions. */
	public static function directions(): iterable {
		yield 'complete' => ['done', true, SetCardDoneHandler::class, 'setDone'];
		yield 'reopen' => ['done', false, SetCardDoneHandler::class, 'setDone'];
		yield 'archive' => ['archived', true, SetCardArchivedHandler::class, 'setArchived'];
		yield 'unarchive' => ['archived', false, SetCardArchivedHandler::class, 'setArchived'];
	}

	/**
	 * @param string $field Explicit field.
	 * @param bool $value Desired state.
	 * @param class-string $class Specific handler.
	 * @param string $method Specific native port method.
	 */
	#[DataProvider('directions')]
	public function testEachDirectionConfirmsThenCallsOnlyItsNativeMethod(string $field, bool $value, string $class, string $method): void {
		$state = CardStatusFixture::state();
		if (!$value) { $state['card'][$field] = $field === 'done' ? 123 : true; }
		$port = $this->createMock(CardStatusGatewayInterface::class);
		$port->method('state')->willReturnCallback(static function () use (&$state): array { return $state; });
		$other = $method === 'setDone' ? 'setArchived' : 'setDone';
		$port->expects(self::never())->method($other);
		$port->expects(self::once())->method($method)->with('actor', 7, $value)->willReturnCallback(static function () use (&$state, $field, $value): void {
			$state['card'][$field] = $field === 'done' ? ($value ? 124 : null) : $value;
			if ($field === 'done' && $value) { $state['card']['stackId'] = 4; $state['list']['id'] = 4; }
		});
		$reads = $this->createMock(DeckGatewayInterface::class);
		$reads->method('findCard')->willReturnCallback(static function () use (&$state) { return CardStatusFixture::card($state); });
		$signer = $this->signer();
		$arguments = ['cardId' => 7, $field => $value];
		$plan = CardStatusPlan::of($class::TOOL, 'actor', $field, $arguments, $state, $signer);
		$handler = new $class($reads, new NullLogger(), static fn () => $port, $this->cardFormatter(), static fn () => $signer);
		$shared = json_decode($handler->handle($arguments + ['plan_state' => $plan['plan_state']], 'actor')['content'][0]['text'], true);
		self::assertTrue($shared['requiresConfirmation']);
		$result = $handler->handle($arguments + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan['plan_state']], 'actor');
		self::assertFalse($result['isError'] ?? false);
		$payload = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertTrue($payload['confirmed']); self::assertTrue($payload['changed']);
		self::assertSame($state['card']['done'], $payload['done']); self::assertSame($state['card']['archived'], $payload['archived']);
	}

	/** A same-second change of status/list/destination or another intent cannot reuse an approved HMAC. */
	public function testSameSecondChangeAndOtherIntentsInvalidatePlan(): void {
		foreach (['done', 'archived', 'stack', 'destination'] as $change) {
			$before = CardStatusFixture::state(); $after = $before;
			if ($change === 'done') { $after['card']['done'] = 123; }
			if ($change === 'archived') { $after['card']['archived'] = true; }
			if ($change === 'stack') { $after['card']['stackId'] = 5; }
			if ($change === 'destination') { $after['autoMove']['destination']['id'] = 6; }
			$arguments = ['cardId' => 7, 'archived' => true]; $signer = $this->signer();
			$old = CardStatusPlan::of(SetCardArchivedHandler::TOOL, 'actor', 'archived', $arguments, $before, $signer);
			$port = $this->createMock(CardStatusGatewayInterface::class); $port->method('state')->willReturn($after);
			$port->expects(self::never())->method('setArchived');
			$handler = new SetCardArchivedHandler($this->createMock(DeckGatewayInterface::class), new NullLogger(), static fn () => $port, $this->cardFormatter(), static fn () => $signer);
			try {
				$handler->handle($arguments + ['confirm_shared' => true, 'plan_state' => $old['plan_state']], 'actor');
				self::fail('Stale plan accepted');
			} catch (PlanChanged $changed) { self::assertNotSame($old['plan_state'], $changed->plan['plan_state']); }
		}
		$state = CardStatusFixture::state(); $signer = $this->signer();
		$a = CardStatusPlan::of(SetCardDoneHandler::TOOL, 'actor', 'done', ['done' => true], $state, $signer)['plan_state'];
		self::assertNotSame($a, CardStatusPlan::of(SetCardDoneHandler::TOOL, 'actor', 'done', ['done' => false], $state, $signer)['plan_state']);
		self::assertNotSame($a, CardStatusPlan::of(SetCardDoneHandler::TOOL, 'other', 'done', ['done' => true], $state, $signer)['plan_state']);
	}

	/** Already done outside a later-configured column must explain why no movement is attempted. */
	public function testNoOpDoesNotRenewTimestampOrRetryTheMove(): void {
		$state = CardStatusFixture::state(); $state['card']['done'] = 123;
		$signer = $this->signer(); $args = ['cardId' => 7, 'done' => true];
		$plan = CardStatusPlan::of(SetCardDoneHandler::TOOL, 'actor', 'done', $args, $state, $signer);
		self::assertFalse($plan['changed']); self::assertFalse($plan['automaticMove']);
		self::assertStringContainsString('does not move completed cards or retry a partial move', $plan['message']);
		$text = (new CardStatusText())->render($plan);
		self::assertStringContainsString('Todo', $text); self::assertStringContainsString('Completed', $text);
		$port = $this->createMock(CardStatusGatewayInterface::class); $port->method('state')->willReturn($state);
		$port->expects(self::never())->method('setDone');
		$reads = $this->createMock(DeckGatewayInterface::class); $reads->method('findCard')->willReturn(CardStatusFixture::card($state));
		$handler = new SetCardDoneHandler($reads, new NullLogger(), static fn () => $port, $this->cardFormatter(), static fn () => $signer);
		$out = json_decode($handler->handle($args + ['plan_state' => $plan['plan_state'], 'confirm_shared' => true], 'actor')['content'][0]['text'], true);
		self::assertFalse($out['changed']); self::assertSame(123, $out['done']);
		foreach ([null, ['id' => 3, 'title' => 'Todo']] as $destination) {
			$state['autoMove']['destination'] = $destination;
			$plan = CardStatusPlan::of(SetCardDoneHandler::TOOL, 'actor', 'done', $args, $state, $signer);
			self::assertStringContainsString('Nothing will be changed', $plan['message']);
		}
	}

	/** Archived-card completion is explicitly narrower than native; archive/unarchive still plans it. */
	public function testArchivedCardMustBeUnarchivedBeforeCompletion(): void {
		$state = CardStatusFixture::state(); $state['card']['archived'] = true;
		$archive = CardStatusPlan::of(SetCardArchivedHandler::TOOL, 'actor', 'archived', ['archived' => false], $state, $this->signer());
		self::assertTrue($archive['changed']);
		$this->expectException(DeckRefusalException::class);
		CardStatusPlan::of(SetCardDoneHandler::TOOL, 'actor', 'done', ['done' => true], $state, $this->signer());
	}

	/** A native second-update failure is partial, and a post-save listener failure is a full observed effect with warning. */
	public function testNativePartialMoveAndPostWriteFailuresAreDifferent(): void {
		$before = CardStatusFixture::state(); $after = $before; $after['card']['done'] = 124;
		$failure = new \RuntimeException('Native details must not be returned');
		$partial = CardStatusResult::of($before, $after, 'done', true, $failure);
		self::assertFalse($partial['confirmed']); self::assertTrue($partial['partial']);
		self::assertStringNotContainsString($failure->getMessage(), json_encode($partial));
		$after['card']['stackId'] = 4;
		$complete = CardStatusResult::of($before, $after, 'done', true, $failure);
		self::assertTrue($complete['confirmed']); self::assertNotEmpty($complete['warnings']);
		$this->expectExceptionObject($failure);
		CardStatusResult::of($before, $before, 'done', true, $failure);
	}

	/** Missing fingerprints are client mistakes and never reach the native port. */
	public function testMissingPlanCannotExecute(): void {
		$port = $this->createMock(CardStatusGatewayInterface::class); $port->expects(self::never())->method('state');
		$signer = $this->signer();
		$handler = new SetCardDoneHandler($this->createMock(DeckGatewayInterface::class), new NullLogger(), static fn () => $port, $this->cardFormatter(), static fn () => $signer);
		$this->expectException(\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException::class);
		$handler->handle(['cardId' => 7, 'done' => true], 'actor');
	}

	/** Permission loss during read-back must return no old title, state or dependency data. */
	public function testReadBackPermissionLossNeverEchoesTheBeforeSnapshot(): void {
		$state = CardStatusFixture::state(); $signer = $this->signer(); $args = ['cardId' => 7, 'done' => true];
		$plan = CardStatusPlan::of(SetCardDoneHandler::TOOL, 'actor', 'done', $args, $state, $signer);
		$calls = 0; $port = $this->createMock(CardStatusGatewayInterface::class);
		$port->method('state')->willReturnCallback(static function () use (&$calls, $state): array {
			if (++$calls === 3) { throw new \OCA\Deck\NoPermissionException('Private permission details'); }
			return $state;
		});
		$port->expects(self::once())->method('setDone')->with('actor', 7, true);
		$reads = $this->createMock(DeckGatewayInterface::class); $reads->expects(self::never())->method('findCard');
		$handler = new SetCardDoneHandler($reads, new NullLogger(), static fn () => $port, $this->cardFormatter(), static fn () => $signer);
		$result = $handler->handle($args + ['plan_state' => $plan['plan_state'], 'confirm_shared' => true], 'actor');
		$data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertFalse($data['confirmed']); self::assertArrayNotHasKey('id', $data);
		self::assertStringNotContainsString('Status <card>', $result['content'][0]['text']);
		self::assertStringNotContainsString('Private permission details', $result['content'][0]['text']);
	}

	/** A native listener can throw InvalidArgumentException after saving, so its type alone never means client error. */
	public function testPostSaveArgumentExceptionStillReportsObservedSuccessWithWarning(): void {
		$state = CardStatusFixture::state(); $signer = $this->signer(); $args = ['cardId' => 7, 'done' => true];
		$plan = CardStatusPlan::of(SetCardDoneHandler::TOOL, 'actor', 'done', $args, $state, $signer);
		$port = $this->createMock(CardStatusGatewayInterface::class);
		$port->method('state')->willReturnCallback(static function () use (&$state): array { return $state; });
		$port->method('setDone')->willReturnCallback(static function () use (&$state): void {
			$state['card']['done'] = 124; $state['card']['stackId'] = 4;
			throw new \InvalidArgumentException('Private listener details');
		});
		$reads = $this->createMock(DeckGatewayInterface::class);
		$reads->method('findCard')->willReturnCallback(static function () use (&$state) { return CardStatusFixture::card($state); });
		$handler = new SetCardDoneHandler($reads, new NullLogger(), static fn () => $port, $this->cardFormatter(), static fn () => $signer);
		$result = $handler->handle($args + ['plan_state' => $plan['plan_state'], 'confirm_shared' => true], 'actor');
		$data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertTrue($data['confirmed']); self::assertNotEmpty($data['warnings']);
		self::assertSame(124, $data['done']); self::assertStringNotContainsString('Private listener details', $result['content'][0]['text']);
	}

}
