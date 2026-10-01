<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Covers the content of the Deck plans: what each write would do, read through the gateway, and the
 * guarantee that a plan never reaches a writing method of the gateway.
 */
final class DeckPreviewTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * Gateway double whose writing methods must never be called, with boards and lists to name.
	 *
	 * Stack 10 lives in `Pessoal` (alice), stack 20 in `Equipe` (pedro), so a plan can show a shared end.
	 *
	 * @return DeckGatewayInterface&MockObject
	 */
	private function readOnlyGateway(): DeckGatewayInterface&MockObject {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		foreach (['createCard', 'updateCard', 'moveCard', 'deleteCard'] as $write) {
			$gateway->expects(self::never())->method($write);
		}
		$gateway->method('listBoards')->willReturn([new Board(['id' => 1, 'title' => 'Pessoal']), new Board(['id' => 2, 'title' => 'Equipe'])]);
		$gateway->method('listStacks')->willReturnCallback(static fn (string $user, int $board): array => $board === 1
			? [new Stack(['id' => 10, 'title' => 'A fazer'])]
			: [new Stack(['id' => 20, 'title' => 'Feito'])]);
		$gateway->method('stackOwnership')->willReturnCallback(fn (string $user, int $stack): array => $stack === 20
			? $this->ownershipOf('pedro', 'Equipe')
			: $this->ownershipOf('alice'));
		$gateway->method('findCard')->willReturn($this->card([
			'id' => 7,
			'stackId' => 10,
			'title' => 'Antes',
			'description' => 'texto',
			'lastModified' => 1_700_000_123,
		]));

		return $gateway;
	}

	private function moduleWith(DeckGatewayInterface $gateway, ?IUserManager $users = null, ?IConfig $config = null): DeckToolModule {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$module = new DeckToolModule(
			$this->createMock(ContainerInterface::class),
			$apps,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ITimeFactory::class),
			$users,
			$config,
		);
		// The gateway is built lazily from the Deck container; the test hands its double in its place.
		(new \ReflectionProperty(DeckToolModule::class, 'gateway'))->setValue($module, $gateway);

		return $module;
	}

	/** Explicit assignees are validated during preview and their display names are escaped by the renderer. */
	public function testCreatePlanIncludesAssignees(): void {
		$gateway = $this->readOnlyGateway();
		$gateway->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro'])
			->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro **Admin**']]);
		$gateway->expects(self::never())->method('assignCardUser');
		$module = $this->moduleWith($gateway);
		$plan = $module->preview('deck_create_card', ['stackId' => 10, 'title' => 'New', 'assignees' => ['pedro']], 'alice');
		self::assertSame('Pedro **Admin**', $plan['card']['assignees'][0]['displayName']);
		self::assertStringContainsString('Pedro \\*\\*Admin\\*\\*', $module->renderPlan('deck_create_card', $plan));
	}

	public function testCreatePlanShowsTheCardAndItsDestination(): void {
		$gateway = $this->readOnlyGateway();
		$gateway->expects(self::never())->method('findCard');

		$plan = $this->moduleWith($gateway)->preview('deck_create_card', [
			'stackId' => 10,
			'title' => 'Novo',
			'description' => 'corpo',
			'duedate' => '2026-10-01',
		], 'alice');

		self::assertSame('deck_create_card', $plan['action']);
		self::assertSame(['title' => 'Novo', 'description' => 'corpo', 'dueDate' => '2026-10-01', 'owner' => 'alice'], $plan['card']);
		self::assertSame(10, $plan['destination']['stackId']);
		self::assertSame('A fazer', $plan['destination']['list']);
		self::assertSame('Pessoal', $plan['destination']['board']);
		self::assertFalse($plan['destination']['shared']);
		self::assertSame([], $plan['shared']);
		self::assertTrue($plan['recoverable']);
	}

	public function testCreatePlanOnSomebodyElsesBoardListsItAsShared(): void {
		$plan = $this->moduleWith($this->readOnlyGateway())->preview('deck_create_card', ['stackId' => 20, 'title' => 'Novo'], 'alice');

		self::assertTrue($plan['destination']['shared']);
		self::assertSame('pedro', $plan['destination']['owner']);
		self::assertSame([$plan['destination']], $plan['shared']);
	}

	public function testEditPlanShowsBeforeAfterAndOnlyTheChangedFields(): void {
		$gateway = $this->readOnlyGateway();
		$gateway->method('cardOwnership')->willReturn($this->ownershipOf('alice'));

		$plan = $this->moduleWith($gateway)->preview('deck_edit_card', ['cardId' => 7, 'title' => 'Depois', 'lastModified' => 5], 'alice');

		self::assertSame(7, $plan['card']['id']);
		self::assertSame(['title' => 'Antes', 'description' => 'texto', 'duedate' => null], $plan['card']['before']);
		self::assertSame(['title' => 'Depois', 'description' => 'texto', 'duedate' => null], $plan['card']['after']);
		self::assertSame(['title'], $plan['changed']);
		self::assertSame(['current' => 1_700_000_123, 'sent' => 5], $plan['lastModified']);
		self::assertSame([], $plan['shared']);
		self::assertTrue($plan['recoverable']);
	}

	/** The stored instant and the day the call sends are the same date for the user, so nothing changes. */
	public function testEditPlanDoesNotReportTheSameDueDateAsAChange(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::never())->method('updateCard');
		$gateway->method('cardOwnership')->willReturn($this->ownershipOf('alice'));
		// Deck keeps the date in UTC: local midnight of 1 October in São Paulo is 03:00 UTC.
		$gateway->method('findCard')->willReturn($this->card([
			'id' => 7,
			'title' => 'Antes',
			'duedate' => new \DateTime('2026-10-01 03:00:00', new \DateTimeZone('UTC')),
		]));
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('America/Sao_Paulo');
		$module = $this->moduleWith($gateway, null, $config);

		$same = $module->preview('deck_edit_card', ['cardId' => 7, 'title' => 'Depois', 'duedate' => '2026-10-01'], 'alice');
		self::assertSame('2026-10-01T00:00:00-03:00', $same['card']['before']['duedate']);
		self::assertSame('2026-10-01T00:00:00-03:00', $same['card']['after']['duedate']);
		self::assertSame(['title'], $same['changed']);

		$other = $module->preview('deck_edit_card', ['cardId' => 7, 'duedate' => '2026-10-02'], 'alice');
		self::assertSame('2026-10-02T00:00:00-03:00', $other['card']['after']['duedate']);
		self::assertSame(['duedate'], $other['changed']);

		$cleared = $module->preview('deck_edit_card', ['cardId' => 7, 'duedate' => null], 'alice');
		self::assertNull($cleared['card']['after']['duedate']);
		self::assertSame(['duedate'], $cleared['changed']);
	}

	/**
	 * @param list<string> $assigned Accounts assigned to card 7 today.
	 * @return DeckGatewayInterface&MockObject Gateway whose writes must never run.
	 */
	private function gatewayWithAssignedCard(array $assigned): DeckGatewayInterface&MockObject {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		foreach (['createCard', 'updateCard', 'moveCard', 'deleteCard', 'assignCardUser', 'unassignCardUser'] as $write) {
			$gateway->expects(self::never())->method($write);
		}
		$gateway->method('cardOwnership')->willReturn($this->ownershipOf('alice'));
		$gateway->method('findCard')->willReturn($this->card([
			'id' => 7,
			'stackId' => 10,
			'title' => 'Antes',
			'assignedUsers' => array_map(
				static fn (string $uid): Assignment => new Assignment(['cardId' => 7, 'participant' => $uid, 'type' => 0]),
				$assigned,
			),
		]));

		return $gateway;
	}

	public function testEditPlanListsTheFinalAssigneesWhoJoinsAndWhoLeaves(): void {
		$gateway = $this->gatewayWithAssignedCard(['ana', 'luis']);
		$gateway->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro'])
			->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro Almeida']]);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getDisplayName')->willReturn(['ana' => 'Ana Souza', 'luis' => 'Luís Lima'][$uid] ?? $uid);

			return $user;
		});
		$module = $this->moduleWith($gateway, $users);

		$plan = $module->preview('deck_edit_card', ['cardId' => 7, 'assign' => ['pedro'], 'unassign' => ['luis', 'nunca']], 'alice');

		self::assertSame(['assignees'], $plan['changed']);
		self::assertSame(
			[['uid' => 'ana', 'displayName' => 'Ana Souza'], ['uid' => 'luis', 'displayName' => 'Luís Lima']],
			$plan['assignees']['before'],
		);
		self::assertSame(
			[['uid' => 'ana', 'displayName' => 'Ana Souza'], ['uid' => 'pedro', 'displayName' => 'Pedro Almeida']],
			$plan['assignees']['after'],
		);
		self::assertSame([['uid' => 'pedro', 'displayName' => 'Pedro Almeida']], $plan['assignees']['added']);
		self::assertSame([['uid' => 'luis', 'displayName' => 'Luís Lima']], $plan['assignees']['removed']);
		self::assertSame(['nunca'], $plan['assignees']['notAssigned']);
		self::assertSame([], $plan['assignees']['alreadyAssigned']);

		$text = (string)$module->renderPlan('deck_edit_card', $plan);
		self::assertStringContainsString('- Assigned: Pedro Almeida', $text);
		self::assertStringContainsString('- Unassigned: Luís Lima', $text);
		self::assertStringContainsString('- Assignees after the change: Ana Souza, Pedro Almeida', $text);
		self::assertStringContainsString('- Ignored, not assigned to the card: nunca', $text);
	}

	public function testEditPlanRefusesAnAccountWithoutAccessBeforeAnythingIsWritten(): void {
		$gateway = $this->gatewayWithAssignedCard([]);
		$gateway->method('validateAssignees')->willThrowException(
			new \OCA\Mcp\Tools\ArgumentValidationException('Invalid argument: assignees', 'assignees', 'each account must exist and have access to the board'),
		);

		$this->expectException(\OCA\Mcp\Tools\ArgumentValidationException::class);

		$this->moduleWith($gateway)->preview('deck_edit_card', ['cardId' => 7, 'assign' => ['intruso']], 'alice');
	}

	public function testEditPlanRefusesAnAccountInBothLists(): void {
		$gateway = $this->gatewayWithAssignedCard(['pedro']);
		$gateway->expects(self::never())->method('findCard');

		$this->expectException(\OCA\Mcp\Tools\ArgumentValidationException::class);

		$this->moduleWith($gateway)->preview('deck_edit_card', ['cardId' => 7, 'assign' => ['pedro'], 'unassign' => ['pedro']], 'alice');
	}

	public function testEditPlanWithoutAssigneeArgumentsHasNoAssigneeSection(): void {
		$gateway = $this->gatewayWithAssignedCard(['ana']);

		$plan = $this->moduleWith($gateway)->preview('deck_edit_card', ['cardId' => 7, 'title' => 'Depois'], 'alice');

		self::assertArrayNotHasKey('assignees', $plan);
		self::assertSame(['title'], $plan['changed']);
	}

	public function testEditPlanWithAssignedAccountsIgnoredChangesNothing(): void {
		$gateway = $this->gatewayWithAssignedCard(['ana']);
		$gateway->expects(self::never())->method('validateAssignees');
		$module = $this->moduleWith($gateway);

		$plan = $module->preview('deck_edit_card', ['cardId' => 7, 'assign' => ['ana']], 'alice');

		self::assertSame([], $plan['changed']);
		self::assertSame(['ana'], $plan['assignees']['alreadyAssigned']);
		self::assertStringContainsString('- Ignored, already assigned to the card: ana', (string)$module->renderPlan('deck_edit_card', $plan));
	}

	public function testEditPlanOnASharedBoardNamesItsOwner(): void {
		$gateway = $this->readOnlyGateway();
		$gateway->method('cardOwnership')->willReturn($this->ownershipOf('pedro', 'Equipe'));

		$plan = $this->moduleWith($gateway)->preview('deck_edit_card', ['cardId' => 7, 'description' => 'novo'], 'alice');

		self::assertSame(['description'], $plan['changed']);
		self::assertTrue($plan['board']['shared']);
		self::assertSame([['board' => 'Equipe', 'owner' => 'pedro', 'ownerDisplayName' => 'Pedro Almeida', 'shared' => true]], $plan['shared']);
	}

	public function testMovePlanShowsOriginAndDestination(): void {
		$plan = $this->moduleWith($this->readOnlyGateway())->preview('deck_move_card', ['cardId' => 7, 'stackId' => 20, 'order' => 2], 'alice');

		self::assertSame(7, $plan['card']['id']);
		self::assertSame('Antes', $plan['card']['title']);
		self::assertSame(['stackId' => 10, 'list' => 'A fazer', 'board' => 'Pessoal'], array_intersect_key($plan['origin'], array_flip(['stackId', 'list', 'board'])));
		self::assertSame(['stackId' => 20, 'list' => 'Feito', 'board' => 'Equipe'], array_intersect_key($plan['destination'], array_flip(['stackId', 'list', 'board'])));
		self::assertSame(2, $plan['order']);
		self::assertFalse($plan['sameList']);
		self::assertSame([$plan['destination']], $plan['shared']);
		self::assertTrue($plan['recoverable']);
	}

	public function testMovePlanWithinTheSameList(): void {
		$plan = $this->moduleWith($this->readOnlyGateway())->preview('deck_move_card', ['cardId' => 7, 'stackId' => 10], 'alice');

		self::assertTrue($plan['sameList']);
		self::assertNull($plan['order']);
		self::assertSame([], $plan['shared']);
	}

	public function testDeletePlanSaysTheCardCannotBeBroughtBack(): void {
		$gateway = $this->readOnlyGateway();
		$gateway->method('cardOwnership')->willReturn($this->ownershipOf('alice'));

		$plan = $this->moduleWith($gateway)->preview('deck_delete_card', ['cardId' => 7], 'alice');

		self::assertSame(7, $plan['card']['id']);
		self::assertSame('Antes', $plan['card']['title']);
		self::assertSame('Pessoal', $plan['board']['board']);
		self::assertSame([], $plan['shared']);
		self::assertFalse($plan['recoverable']);
		self::assertNotSame('', $plan['consequence']);
	}

	/** @return array<string, array{mixed}> */
	public static function unconfirmedProvider(): array {
		return ['absent' => [null], 'false' => [false]];
	}

	/**
	 * Through the registry, anything but `confirm: true` answers with the plan and never moves the card.
	 *
	 * @param bool|null $confirm null leaves the argument out.
	 */
	#[DataProvider('unconfirmedProvider')]
	public function testRegistryAnswersWithThePlanUnlessConfirmed(mixed $confirm): void {
		$arguments = ['cardId' => 7, 'stackId' => 20];
		if ($confirm !== null) {
			$arguments['confirm'] = $confirm;
		}

		$result = $this->registry($this->readOnlyGateway())->call('deck_move_card', $arguments, 'alice');

		self::assertNotTrue($result['isError'] ?? false);
		$structured = $result['structuredContent'] ?? $this->payload($result);
		self::assertTrue($structured['requiresConfirmation']);
		self::assertSame('deck_move_card', $structured['action']);
	}

	/** `confirm` is a boolean: an explicit null is refused by the schema, so it never reaches the write either. */
	public function testExplicitNullConfirmIsRefusedWithoutWriting(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->registry($this->readOnlyGateway())->call('deck_move_card', ['cardId' => 7, 'stackId' => 20, 'confirm' => null], 'alice');
	}

	public function testRegistryRunsTheWriteOnceConfirmed(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('cardOwnership')->willReturn($this->ownershipOf('alice'));
		$gateway->method('stackOwnership')->willReturn($this->ownershipOf('alice'));
		$gateway->method('cardBoardId')->willReturn(1);
		$gateway->expects(self::once())->method('moveCard')->with('alice', 7, 20, null)->willReturn($this->card(['id' => 7, 'stackId' => 20]));

		$result = $this->registry($gateway)->call('deck_move_card', ['cardId' => 7, 'stackId' => 20, 'confirm' => true], 'alice');

		self::assertNotTrue($result['isError'] ?? false);
		$structured = $result['structuredContent'] ?? $this->payload($result);
		self::assertArrayNotHasKey('requiresConfirmation', $structured);
	}

	private function registry(DeckGatewayInterface $gateway): ToolRegistry {
		$policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
		foreach (GrantPolicy::CATALOG['deck'] as $operation) {
			$policy->setGrant('alice', 'deck', $operation, true);
		}
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		return new ToolRegistry([$this->moduleWith($gateway, $users)], $policy, $apps, $users, $this->createMock(LoggerInterface::class));
	}
}
