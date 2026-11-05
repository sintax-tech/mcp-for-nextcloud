<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\NoPermissionException;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\{DeckConflictException, DeckPlanRenderer};
use OCA\Mcp\Tools\Deck\Handler\ChangeCardDependencyHandler;
use OCA\Mcp\Mcp\{Confirmation\PlanState, Confirmation\PlanChanged, Tools\Validation\ArgumentValidationException};
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

require_once __DIR__ . '/Stubs/deck_stubs.php';

final class DependencyWriteHandlerTest extends TestCase {
	use DeckTestHelpers;

	private function state(bool $present = false, string $owner = 'alice'): array {
		$target = ['card' => $this->card(['id' => 8, 'title' => 'Target']), 'boardId' => 20];
		return ['origin' => ['card' => $this->card(['id' => 7, 'title' => 'Origin']), 'boardId' => 20,
			'targets' => $present ? [$target] : []], 'target' => $target, 'present' => $present,
			'board' => $this->ownershipOf($owner), 'graph' => null];
	}

	private function handler(DeckGatewayInterface $gateway, bool $assign = true): ChangeCardDependencyHandler {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getSystemValueString')->willReturn('secret');
		return new ChangeCardDependencyHandler($gateway, $this->createMock(LoggerInterface::class),
			$this->cardFormatter(), fn () => new PlanState($config), $assign);
	}

	private function args(ChangeCardDependencyHandler $handler): array {
		$args = ['cardId' => 7, 'targetId' => 8];
		return $args + ['confirm' => true, 'plan_state' => $handler->preview($args, 'alice')['plan_state']];
	}

	public function testPreviewShowsDirectionAndLimitationsWithoutWriting(): void {
		$g = $this->createMock(DeckGatewayInterface::class);
		$g->method('dependencyChangeState')->willReturn($this->state());
		$g->expects(self::never())->method('changeDependency');
		$p = $this->handler($g)->preview(['cardId' => 7, 'targetId' => 8], 'alice');
		self::assertFalse($p['before']);
		self::assertTrue($p['after']);
		self::assertSame('origin.dependentCards contains target', $p['direction']);
		self::assertStringContainsString('cards you cannot read', $p['message']);
		self::assertStringContainsString('those cards', $p['message']);
		self::assertStringContainsString('Origin', (new DeckPlanRenderer())->render(ChangeCardDependencyHandler::ADD, $p));
	}

	public function testPersistThenThrowReadsBackForBothDirectionsWithoutRetry(): void {
		foreach ([true, false] as $assign) {
			$s = $this->state(!$assign);
			$g = $this->createMock(DeckGatewayInterface::class);
			$g->method('dependencyChangeState')->willReturnCallback(function () use (&$s): array { return $s; });
			$g->expects(self::once())->method('changeDependency')->willReturnCallback(function () use (&$s, $assign): void {
				$s = $this->state($assign);
				throw new \RuntimeException('listener failed');
			});
			$h = $this->handler($g, $assign);
			$r = $this->payload($h->handle($this->args($h), 'alice'));
			self::assertTrue($r['confirmed']);
			self::assertSame($assign, $r['present']);
			self::assertSame('observed-state', $r['verification']);
			self::assertStringContainsString('Another actor', $r['warnings'][0]);
		}
	}

	public function testCleanFirstFailureIsTheUsualErrorAndUnknownReadbackDoesNotEchoOldTarget(): void {
		foreach ([false, true] as $unknown) {
			$written = false;
			$g = $this->createMock(DeckGatewayInterface::class);
			$g->method('dependencyChangeState')->willReturnCallback(function () use (&$written, $unknown): array {
				if ($written && $unknown) { throw new NoPermissionException(); }
				return $this->state();
			});
			$g->expects(self::once())->method('changeDependency')->willReturnCallback(function () use (&$written): void {
				$written = true;
				throw new NoPermissionException();
			});
			$h = $this->handler($g);
			$result = $h->handle($this->args($h), 'alice');
			if (!$unknown) { self::assertTrue($result['isError']); continue; }
			$r = $this->payload($result);
			self::assertFalse($r['confirmed']);
			self::assertArrayNotHasKey('target', $r);
			self::assertStringContainsString('do not repeat', $r['message']);
		}
	}

	public function testSharedConfirmationAndIdempotenceStillRequireStateValidation(): void {
		$g = $this->createMock(DeckGatewayInterface::class);
		$g->method('dependencyChangeState')->willReturn($this->state(true, 'bob'));
		$g->expects(self::never())->method('changeDependency');
		$h = $this->handler($g);
		$args = $this->args($h);
		self::assertSame('shared', $this->payload($h->handle($args, 'alice'))['scope']);
		self::assertFalse($this->payload($h->handle($args + ['confirm_shared' => true], 'alice'))['changed']);
	}

	public function testStalePlanAndPrewriteConflictRequireNewConfirmation(): void {
		foreach ([false, true] as $conflict) {
			$s = $this->state();
			$g = $this->createMock(DeckGatewayInterface::class);
			$g->method('dependencyChangeState')->willReturnCallback(function () use (&$s): array { return $s; });
			$g->expects($conflict ? self::once() : self::never())->method('changeDependency')->willThrowException(new DeckConflictException());
			$h = $this->handler($g);
			$a = $this->args($h);
			if (!$conflict) { $s['board']['name'] = 'Renamed'; }
			try { $h->handle($a, 'alice'); self::fail('Stale plan accepted'); }
			catch (PlanChanged $e) { self::assertSame(ChangeCardDependencyHandler::ADD, $e->plan['action']); }
		}
	}

	public function testHiddenAndMissingDirectTargetsReturnIdenticalPublicRefusals(): void {
		foreach ([true, false] as $assign) {
			$results = [];
			foreach ([new NoPermissionException(), new \OCP\AppFramework\Db\DoesNotExistException('missing')] as $error) {
				$g = $this->createMock(DeckGatewayInterface::class);
				$g->method('dependencyChangeState')->willThrowException($error);
				$g->expects(self::never())->method('changeDependency');
				$results[] = $this->handler($g, $assign)->handle(['cardId' => 7, 'targetId' => 8, 'confirm' => true], 'alice');
			}
			self::assertSame($results[0], $results[1]);
		}
	}

	public function testMissingPlanNeverWrites(): void {
		$g = $this->createMock(DeckGatewayInterface::class);
		$g->method('dependencyChangeState')->willReturn($this->state());
		$g->expects(self::never())->method('changeDependency');
		$this->expectException(ArgumentValidationException::class);
		$this->handler($g)->handle(['cardId' => 7, 'targetId' => 8, 'confirm' => true], 'alice');
	}
}
