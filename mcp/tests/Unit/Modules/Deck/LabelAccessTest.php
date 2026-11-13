<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Integration\DeckLabelCatalog;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestModule;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/** Discovery, direct calls and confirmations share the real default-denied policy and native boundary. */
final class LabelAccessTest extends TestCase {
	use \OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
	private GrantPolicy $policy;
	private DeckGatewayInterface $gateway;
	private DeckLabelCatalog $labels;
	private array $state;

	/** @return ToolRegistry Real catalog and policy with a controlled native boundary. */
	private function registry(?bool $guestAllowed = null): ToolRegistry {
		$data = new InMemoryConfig();
		$config = $data->mock($this);
		$this->policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		$native = $this->createMock(\Psr\Container\ContainerInterface::class);
		$native->method('get')->willReturnCallback(fn (string $id): mixed => $id === GrantPolicy::class ? $this->policy : null);
		$apps = $this->createMock(\OCP\App\IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(\OCP\IUserManager::class);
		$users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$logger = $this->createMock(LoggerInterface::class);
		$module = DeckTestModule::build($native, $apps, $logger, $this->createMock(\OCP\IURLGenerator::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class), $users, $config);
		$this->state = LabelPlanFixture::state();
		$this->labels = $this->createMock(DeckLabelCatalog::class);
		$this->labels->method('boardLabelState')->willReturnCallback(fn (): array => $this->state);
		$this->labels->method('labelState')->willReturnCallback(fn (): array => $this->state);
		$this->gateway = $this->createMock(DeckGatewayInterface::class);
		$this->gateway->method('boardLabels')->willReturn($this->labels);
		$this->gateway->method('stackOwnership')->willReturn($this->state['board']);
		DeckTestModule::setGateway($module, $this->gateway);
		$guests = $guestAllowed === null ? null : $this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);
		$guests?->method('allowsApp')->willReturn($guestAllowed);
		return new ToolRegistry([$module], $this->policy, $apps, $users, $logger, $guests);
	}

	/** @param array $result MCP envelope. @return array Decoded payload. */
	private function payload(array $result): array {
		self::assertFalse($result['isError'] ?? false, json_encode($result));
		return $result['structuredContent'] ?? json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
	}

	/** @return void Neither old write grant authorizes discovery or direct invocation of any new tool. */
	public function testOldGrantsDoNotExposeOrAllowNewTools(): void {
		$registry = $this->registry();
		$this->policy->setGrant('alice', 'deck', 'edit', true);
		$this->policy->setGrant('alice', 'deck', 'delete', true);
		foreach (['deck_create_label', 'deck_update_label', 'deck_delete_label'] as $tool) {
			self::assertNotContains($tool, array_column($registry->list('alice'), 'name'));
			try {
				$registry->call($tool, ['boardId' => 20, 'title' => 'new'], 'alice');
				self::fail('Old grants admitted a label tool');
			} catch (\InvalidArgumentException) {
			}
		}
		$this->labels->expects(self::never())->method('createLabel');
	}

	/** @return void Empty, existing and missing labels all need their grant before any card or label is written. */
	public function testLabelsParameterCannotBypassGrant(): void {
		$registry = $this->registry();
		$this->policy->setGrant('alice', 'deck', 'create', true);
		$this->gateway->expects(self::never())->method('createCard');
		$this->labels->expects(self::never())->method('createLabel');
		foreach ([[], [1], ['new']] as $labels) {
			foreach ([false, true] as $confirm) {
				$result = $registry->call('deck_create_card', ['stackId' => 2, 'title' => 'Card', 'labels' => $labels, 'confirm' => $confirm], 'alice');
				self::assertTrue($result['isError']);
				self::assertStringContainsString('deck.labels', $result['content'][0]['text']);
			}
		}
	}

	/** @return void A native ACL denial remains effective with the new grant. */
	public function testNativeDenialCannotBeOverriddenByGrant(): void {
		$registry = $this->registry();
		$this->policy->setGrant('alice', 'deck', 'labels', true);
		$this->labels->method('boardLabelState')->willThrowException(new \OCA\Deck\NoPermissionException('private-board-secret'));
		$this->labels->expects(self::never())->method('createLabel');
		$result = $registry->call('deck_create_label', ['boardId' => 20, 'title' => 'new'], 'alice');
		self::assertTrue($result['isError']);
		self::assertStringNotContainsString('private-board-secret', json_encode($result));
	}

	/** @return void Preview has no effects and a withdrawn grant blocks the same confirmed request. */
	public function testRevocationAfterPreviewBlocksExecution(): void {
		$registry = $this->registry();
		$this->policy->setGrant('alice', 'deck', 'labels', true);
		self::assertContains('deck_create_label', array_column($registry->list('alice'), 'name'));
		$this->labels->expects(self::never())->method('createLabel');
		$args = ['boardId' => 20, 'title' => 'new'];
		$plan = $this->payload($registry->call('deck_create_label', $args, 'alice'));
		self::assertTrue($plan['requiresConfirmation']);
		$this->policy->setGrant('alice', 'deck', 'labels', false);
		$this->expectException(\InvalidArgumentException::class);
		$registry->call('deck_create_label', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
	}

	/** @return void Altered contents require a new plan, while an unchanged approved request writes once. */
	public function testCreateBindsRequestAndWritesOnce(): void {
		$registry = $this->registry();
		$this->policy->setGrant('alice', 'deck', 'labels', true);
		$args = ['boardId' => 20, 'title' => 'new'];
		$plan = $this->payload($registry->call('deck_create_label', $args, 'alice'));
		$changed = $this->payload($registry->call('deck_create_label', ['boardId' => 20, 'title' => 'other', 'confirm' => true, 'plan_state' => $plan['plan_state']], 'alice'));
		self::assertTrue($changed['requiresConfirmation']);
		$this->labels->expects(self::once())->method('createLabel')->willReturn(['id' => 3, 'boardId' => 20, 'title' => 'new', 'color' => 'ff0000']);
		$result = $this->payload($registry->call('deck_create_label', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice'));
		self::assertTrue($result['confirmed']);
		self::assertSame(3, $result['label']['id']);
	}

	/** @return void Guests exclusion applies to discovery and direct invocation with explicit grants. */
	public function testGuestRestrictionsStillBlockNewTools(): void {
		$registry = $this->registry(false);
		$this->policy->setGrant('alice', 'deck', 'labels', true);
		self::assertNotContains('deck_create_label', array_column($registry->list('alice'), 'name'));
		$this->labels->expects(self::never())->method('createLabel');
		$this->expectException(\InvalidArgumentException::class);
		$registry->call('deck_create_label', ['boardId' => 20, 'title' => 'new', 'confirm' => true], 'alice');
	}

	/** @return void Equal card counts do not mask a different deletion audience. */
	public function testSwappedAssociationsInvalidateDelete(): void {
		$registry = $this->registry();
		$this->policy->setGrant('alice', 'deck', 'labels', true);
		$args = ['labelId' => 1];
		$plan = $this->payload($registry->call('deck_delete_label', $args, 'alice'));
		$this->state['cardIds'] = [7, 9];
		$this->labels->expects(self::never())->method('deleteLabel');
		$changed = $this->payload($registry->call('deck_delete_label', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice'));
		self::assertTrue($changed['requiresConfirmation']);
		self::assertNotSame($plan['plan_state'], $changed['plan_state']);
	}
	/** @return void A signed card plan cannot authorize a different title; the approved request writes exactly once. */
	public function testSignedCardPlanAndSuccessfulExistingLabelAssignment(): void {
		$registry = $this->registry();
		$this->policy->setGrant('alice', 'deck', 'create', true);
		$this->policy->setGrant('alice', 'deck', 'labels', true);
		$this->labels->method('stackLabels')->willReturn(['boardId' => 20, 'labels' => $this->state['labels'], 'manage' => false]);
		$args = ['stackId' => 2, 'title' => 'Card', 'labels' => [1]];
		$plan = $this->payload($registry->call('deck_create_card', $args, 'alice'));
		self::assertSame(20, $plan['destination']['boardId']);
		$changed = $this->payload($registry->call('deck_create_card', ['stackId' => 2, 'title' => 'Other', 'labels' => [1],
			'confirm' => true, 'plan_state' => $plan['plan_state']], 'alice'));
		self::assertTrue($changed['requiresConfirmation']);
		$this->gateway->expects(self::once())->method('createCard')->willReturn($this->card(['id' => 9]));
		$this->labels->expects(self::never())->method('createLabel');
		$this->labels->expects(self::once())->method('assignCardLabel')->with('alice', 9, 1);
		$written = $this->payload($registry->call('deck_create_card', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice'));
		self::assertSame(9, $written['id']);
		self::assertSame([1], array_column($written['labels'], 'id'));
	}

	/** @return void The labels grant never substitutes for the original card creation grant. */
	public function testLabelGrantAloneCannotCreateCards(): void {
		$registry = $this->registry();
		$this->policy->setGrant('alice', 'deck', 'labels', true);
		$this->gateway->expects(self::never())->method('createCard');
		$this->labels->expects(self::never())->method('createLabel');
		$this->expectException(\InvalidArgumentException::class);
		$registry->call('deck_create_card', ['stackId' => 2, 'title' => 'Card', 'labels' => ['new'], 'confirm' => true], 'alice');
	}

	/** @return void Revocation during native preflight prevents the first card write. */
	public function testGrantIsRecheckedAtTheCardSink(): void {
		$registry = $this->registry();
		$this->policy->setGrant('alice', 'deck', 'labels', true);
		$this->policy->setGrant('alice', 'deck', 'create', true);
		$reads = 0;
		$this->labels->method('stackLabels')->willReturnCallback(function () use (&$reads): array {
			if (++$reads === 3) { $this->policy->setGrant('alice', 'deck', 'labels', false); }
			return ['boardId' => 20, 'labels' => $this->state['labels'], 'manage' => true];
		});
		$args = ['stackId' => 2, 'title' => 'Card', 'labels' => [1]];
		$plan = $this->payload($registry->call('deck_create_card', $args, 'alice'));
		$this->gateway->expects(self::never())->method('createCard');
		$this->labels->expects(self::never())->method('assignCardLabel');
		$result = $registry->call('deck_create_card', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		self::assertTrue($result['isError']);
	}

	/** @return void A label grant revoked after execution preflight cannot reach LabelService. */
	public function testGrantIsRecheckedAtTheLabelSink(): void {
		$registry = $this->registry();
		$this->policy->setGrant('alice', 'deck', 'labels', true);
		$reads = 0;
		$this->labels->method('boardLabelState')->willReturnCallback(function () use (&$reads): array {
			if (++$reads === 3) { $this->policy->setGrant('alice', 'deck', 'labels', false); }
			return $this->state;
		});
		$args = ['boardId' => 20, 'title' => 'new'];
		$plan = $this->payload($registry->call('deck_create_label', $args, 'alice'));
		$this->labels->expects(self::never())->method('createLabel');
		$result = $registry->call('deck_create_label', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		self::assertTrue($result['isError']);
	}

}
