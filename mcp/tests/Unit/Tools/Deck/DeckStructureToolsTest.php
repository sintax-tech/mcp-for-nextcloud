<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\ArgumentValidator;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckRefusalException;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolPresentation;
use OCA\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Tools\WriteGate;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
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
 * The four tools that build a Deck from scratch as the registry sees them: their definitions, their plans and
 * the rule that a plan never writes.
 */
final class DeckStructureToolsTest extends TestCase {
	use DeckTestHelpers;

	private const WRITES = ['createBoard', 'createStack', 'deleteEmptyStack', 'deleteEmptyBoard', 'createCard', 'assignCardUser'];

	/**
	 * @return DeckGatewayInterface&MockObject Gateway whose writes must never run during a plan.
	 */
	private function readOnlyGateway(): DeckGatewayInterface&MockObject {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		foreach (self::WRITES as $write) {
			$gateway->expects(self::never())->method($write);
		}
		$gateway->method('boardOwnership')->willReturnCallback(fn (string $user, int $board): array => $board === 2
			? $this->ownershipOf('pedro', 'Equipe')
			: $this->ownershipOf('alice', 'Pessoal'));
		$gateway->method('stackOwnership')->willReturnCallback(fn (string $user, int $stack): array => $stack === 20
			? $this->ownershipOf('pedro', 'Equipe')
			: $this->ownershipOf('alice', 'Pessoal'));
		$gateway->method('listBoards')->willReturn([new Board(['id' => 1, 'title' => 'Pessoal']), new Board(['id' => 2, 'title' => 'Equipe'])]);
		$gateway->method('listStacks')->willReturnCallback(static fn (string $user, int $board): array => $board === 1
			? [new Stack(['id' => 10, 'boardId' => 1, 'title' => 'A fazer']), new Stack(['id' => 11, 'boardId' => 1, 'title' => 'Feito'])]
			: [new Stack(['id' => 20, 'boardId' => 2, 'title' => 'Revisão'])]);

		return $gateway;
	}

	private function moduleWith(DeckGatewayInterface $gateway, ?IUserManager $users = null): DeckToolModule {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$module = new DeckToolModule(
			$this->createMock(ContainerInterface::class),
			$apps,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ITimeFactory::class),
			$users,
		);
		(new \ReflectionProperty(DeckToolModule::class, 'gateway'))->setValue($module, $gateway);

		return $module;
	}

	/**
	 * @param string $name Tool name.
	 * @return array<string, mixed> Definition of that tool.
	 */
	private function definitionOf(string $name): array {
		foreach ($this->moduleWith($this->createMock(DeckGatewayInterface::class))->definitions() as $definition) {
			if ($definition['name'] === $name) {
				return $definition;
			}
		}

		self::fail('Tool not declared: ' . $name);
	}

	public function testTheFourToolsFollowTheExistingOnes(): void {
		$names = array_column($this->moduleWith($this->createMock(DeckGatewayInterface::class))->definitions(), 'name');

		self::assertSame(['deck_create_board', 'deck_create_stack', 'deck_delete_stack', 'deck_delete_board'], array_slice($names, 9));
	}

	/** @return array<string, array{string, string, list<string>}> */
	public static function toolProvider(): array {
		return [
			'create board' => ['deck_create_board', 'create', ['title']],
			'create stack' => ['deck_create_stack', 'create', ['boardId', 'title']],
			'delete stack' => ['deck_delete_stack', 'delete', ['stackId']],
			'delete board' => ['deck_delete_board', 'delete', ['boardId']],
		];
	}

	/**
	 * @param string $name Tool name.
	 * @param string $operation Grant operation the tool needs.
	 * @param list<string> $required Arguments the schema demands.
	 */
	#[DataProvider('toolProvider')]
	public function testEachToolReusesTheCreateAndDeleteGrants(string $name, string $operation, array $required): void {
		$definition = $this->definitionOf($name);

		self::assertSame('deck', $definition['module']);
		self::assertSame('deck', $definition['app']);
		self::assertSame($operation, $definition['operation']);
		self::assertContains($operation, GrantPolicy::CATALOG['deck']);
		self::assertSame($required, $definition['inputSchema']['required']);
		self::assertFalse($definition['inputSchema']['additionalProperties']);
		self::assertTrue(WriteGate::isWrite($definition));
		self::assertArrayNotHasKey('confirm', $definition['inputSchema']['properties'], 'the registry publishes confirm');
	}

	public function testOnlyTheToolsOnAnExistingBoardOfSomebodyElseOfferConfirmShared(): void {
		foreach (['deck_create_stack', 'deck_delete_stack'] as $name) {
			$definition = $this->definitionOf($name);
			self::assertSame('boolean', $definition['inputSchema']['properties']['confirm_shared']['type'], $name);
			self::assertStringContainsString('confirm_shared: true', $definition['description'], $name);
		}
		foreach (['deck_create_board', 'deck_delete_board'] as $name) {
			$definition = $this->definitionOf($name);
			self::assertArrayNotHasKey('confirm_shared', $definition['inputSchema']['properties'], $name);
			self::assertStringNotContainsString('confirm_shared', $definition['description'], $name);
		}
	}

	public function testDescriptionsTeachTheWorkflowAndTheEmptyRule(): void {
		$create = $this->definitionOf('deck_create_board')['description'];
		self::assertStringContainsString('one call', $create);
		self::assertStringContainsString('20 lists', $create);
		self::assertStringContainsString('100 cards', $create);
		self::assertStringContainsString('only to you', $create);
		foreach (['deck_delete_stack', 'deck_delete_board'] as $name) {
			$description = $this->definitionOf($name)['description'];
			self::assertMatchesRegularExpression('/\bonly\b/', $description, $name);
			self::assertStringContainsString('archived', $description, $name);
			self::assertStringContainsString('trash', $description, $name);
			// Accepted residual window (third round): a card created right after the second count may go along.
			self::assertStringContainsString('right after the second count', $description, $name);
		}
		self::assertStringContainsString('deck_create_board', implode(' ', $this->moduleWith($this->createMock(DeckGatewayInterface::class))->guideNotes()));
	}

	public function testTheSchemaOfCreateBoardValidatesTheWholeNestedStructure(): void {
		$schema = $this->definitionOf('deck_create_board')['inputSchema'];
		$good = ['title' => 'P', 'color' => '#0082c9', 'stacks' => [['title' => 'L', 'cards' => [['title' => 'C', 'description' => 'd', 'duedate' => null, 'assignees' => ['alice']]]]]];

		self::assertSame($good['stacks'], ArgumentValidator::validate($schema, $good)['stacks']);
		foreach ([
			'a title over 100' => ['title' => str_repeat('a', 101)],
			'21 lists' => ['title' => 'P', 'stacks' => array_fill(0, 21, ['title' => 'L'])],
			'an unknown key in a list' => ['title' => 'P', 'stacks' => [['title' => 'L', 'color' => 'red']]],
			'an unknown key in a card' => ['title' => 'P', 'stacks' => [['title' => 'L', 'cards' => [['title' => 'C', 'labels' => []]]]]],
			'a card title over 255' => ['title' => 'P', 'stacks' => [['title' => 'L', 'cards' => [['title' => str_repeat('a', 256)]]]]],
			'a list without title' => ['title' => 'P', 'stacks' => [['cards' => []]]],
			'cards that are not a list' => ['title' => 'P', 'stacks' => [['title' => 'L', 'cards' => 'C']]],
			'more than 100 assignees' => ['title' => 'P', 'stacks' => [['title' => 'L', 'cards' => [['title' => 'C', 'assignees' => array_fill(0, 101, 'a')]]]]],
		] as $why => $arguments) {
			try {
				ArgumentValidator::validate($schema, $arguments);
				self::fail('accepted ' . $why);
			} catch (ArgumentValidationException) {
			}
		}
	}

	public function testTheSchemaOfCreateStackBoundsTheOrderLikeMoveCard(): void {
		$schema = $this->definitionOf('deck_create_stack')['inputSchema'];

		self::assertSame(0, ArgumentValidator::validate($schema, ['boardId' => 1, 'title' => 'L', 'order' => 0])['order']);
		$this->expectException(ArgumentValidationException::class);
		ArgumentValidator::validate($schema, ['boardId' => 1, 'title' => 'L', 'order' => 100000]);
	}

	public function testCreateBoardPlanIsTheTreeThatWillBeBuilt(): void {
		$users = $this->createMock(IUserManager::class);
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice Silva');
		$users->method('get')->willReturn($user);
		$module = $this->moduleWith($this->readOnlyGateway(), $users);

		$plan = $module->preview('deck_create_board', [
			'title' => 'Projeto Alfa',
			'color' => '#FF0000',
			'stacks' => [
				['title' => 'A fazer', 'cards' => [
					['title' => 'Briefing', 'duedate' => '2026-10-05', 'assignees' => ['alice']],
					['title' => 'Escopo'],
				]],
				['title' => 'Feito'],
			],
		], 'alice');

		self::assertSame('deck_create_board', $plan['action']);
		self::assertSame(['title' => 'Projeto Alfa', 'color' => 'ff0000', 'colorGiven' => true], $plan['board']);
		self::assertSame('alice', $plan['owner']);
		self::assertSame(['stacks' => 2, 'cards' => 2], $plan['totals']);
		self::assertSame(['stacks' => 20, 'cards' => 100], $plan['limits']);
		self::assertSame(['A fazer', 'Feito'], array_column($plan['stacks'], 'title'));
		self::assertSame('Briefing', $plan['stacks'][0]['cards'][0]['title']);
		self::assertSame('2026-10-05', $plan['stacks'][0]['cards'][0]['duedate']);
		self::assertSame([['uid' => 'alice', 'displayName' => 'Alice Silva']], $plan['stacks'][0]['cards'][0]['assignees']);
		self::assertSame([], $plan['stacks'][1]['cards']);
		self::assertSame([], $plan['shared']);
		self::assertNotSame('', $plan['message']);
	}

	/** The plan refuses what the execution would, naming the field, so the user is never asked to approve it. */
	public function testCreateBoardPlanRefusesAnAssigneeThatCannotHaveAccessYet(): void {
		$module = $this->moduleWith($this->readOnlyGateway());

		try {
			$module->preview('deck_create_board', ['title' => 'P', 'stacks' => [['title' => 'L', 'cards' => [['title' => 'C', 'assignees' => ['pedro']]]]]], 'alice');
			self::fail('planned an assignment that would fail');
		} catch (ArgumentValidationException $e) {
			self::assertSame('stacks[0].cards[0].assignees', $e->details()['field']);
			self::assertStringContainsString('share the board', $e->clientMessage());
		}
	}

	public function testCreateStackPlanNamesTheBoardAndThePosition(): void {
		$plan = $this->moduleWith($this->readOnlyGateway())->preview('deck_create_stack', ['boardId' => 1, 'title' => ' Revisão '], 'alice');

		self::assertSame('deck_create_stack', $plan['action']);
		self::assertSame(['title' => 'Revisão', 'order' => null], $plan['stack']);
		self::assertSame('Pessoal', $plan['board']['board']);
		self::assertFalse($plan['board']['shared']);
		self::assertSame([], $plan['shared']);
	}

	public function testCreateStackPlanOnABoardOfSomebodyElseIsShared(): void {
		$plan = $this->moduleWith($this->readOnlyGateway())->preview('deck_create_stack', ['boardId' => 2, 'title' => 'Nova', 'order' => 1], 'alice');

		self::assertSame(1, $plan['stack']['order']);
		self::assertTrue($plan['board']['shared']);
		self::assertCount(1, $plan['shared']);
	}

	public function testDeleteStackPlanOfAnEmptyListSaysItGoesToTheTrash(): void {
		$gateway = $this->readOnlyGateway();
		$gateway->expects(self::once())->method('stackCardCount')->with('alice', 10)->willReturn(0);

		$plan = $this->moduleWith($gateway)->preview('deck_delete_stack', ['stackId' => 10], 'alice');

		self::assertSame('deck_delete_stack', $plan['action']);
		self::assertSame(['id' => 10, 'title' => 'A fazer', 'cards' => 0], $plan['stack']);
		self::assertSame('Pessoal', $plan['board']['board']);
		self::assertTrue($plan['recoverable']);
		self::assertStringContainsString('trash', $plan['consequence']);
	}

	/** A list with cards has no plan: the refusal comes first and the user is not asked to confirm what will fail. */
	public function testDeleteStackPlanOfAListWithCardsIsTheRefusal(): void {
		$gateway = $this->readOnlyGateway();
		$gateway->method('stackCardCount')->willReturn(4);

		try {
			$this->moduleWith($gateway)->preview('deck_delete_stack', ['stackId' => 10], 'alice');
			self::fail('planned the deletion of a list with cards');
		} catch (ToolFailure $e) {
			self::assertSame('The list has 4 cards; move or delete them first.', $e->getMessage());
		}
	}

	public function testDeleteBoardPlanListsTheEmptyListsThatGoAlong(): void {
		$gateway = $this->readOnlyGateway();
		$gateway->expects(self::once())->method('boardCardCount')->with('alice', 1)->willReturn(0);

		$plan = $this->moduleWith($gateway)->preview('deck_delete_board', ['boardId' => 1], 'alice');

		self::assertSame('deck_delete_board', $plan['action']);
		self::assertSame(['id' => 1, 'title' => 'Pessoal', 'cards' => 0], $plan['board']);
		self::assertSame(['A fazer', 'Feito'], $plan['stacks']);
		self::assertTrue($plan['recoverable']);
		self::assertStringContainsString('trash', $plan['consequence']);
	}

	public function testDeleteBoardPlanRefusesABoardOfSomebodyElseAndABoardWithCards(): void {
		$gateway = $this->readOnlyGateway();
		$gateway->method('boardCardCount')->willReturn(2);
		$module = $this->moduleWith($gateway);

		foreach ([
			2 => 'Only the owner of a board can delete it.',
			1 => 'The board has 2 cards; move or delete them first.',
		] as $boardId => $message) {
			try {
				$module->preview('deck_delete_board', ['boardId' => $boardId], 'alice');
				self::fail('planned a deletion that would be refused');
			} catch (ToolFailure $e) {
				self::assertSame($message, $e->getMessage());
			}
		}
	}

	public function testThePresentationNamesTheFourTools(): void {
		foreach (['deck_create_board', 'deck_create_stack', 'deck_delete_stack', 'deck_delete_board'] as $tool) {
			self::assertNotSame(ToolPresentation::title($tool), ucfirst(str_replace('_', ' ', $tool)), $tool);
		}
		self::assertSame('Create Deck board', ToolPresentation::title('deck_create_board'));
	}

	/** Through the registry: the first call is the plan, and nothing is built until the same call repeats with confirm. */
	public function testRegistryBuildsNothingUntilConfirmed(): void {
		$gateway = $this->readOnlyGateway();
		$registry = $this->registry($gateway);

		$result = $registry->call('deck_create_board', ['title' => 'Projeto', 'stacks' => [['title' => 'A fazer']]], 'alice');

		self::assertNotTrue($result['isError'] ?? false);
		$structured = $result['structuredContent'] ?? $this->payload($result);
		self::assertTrue($structured['requiresConfirmation']);
		self::assertSame('deck_create_board', $structured['action']);
	}

	public function testRegistryBuildsTheBoardOnceConfirmed(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())->method('createBoard')->with('alice', 'Projeto', '0082c9')->willReturn(new Board(['id' => 3, 'title' => 'Projeto']));
		$gateway->expects(self::once())->method('createStack')->with('alice', 3, 'A fazer', 0)->willReturn(new Stack(['id' => 10, 'title' => 'A fazer']));

		$result = $this->registry($gateway)->call('deck_create_board', ['title' => 'Projeto', 'stacks' => [['title' => 'A fazer']], 'confirm' => true], 'alice');

		self::assertNotTrue($result['isError'] ?? false);
		$structured = $result['structuredContent'] ?? $this->payload($result);
		self::assertTrue($structured['complete']);
	}

	public function testRegistryAnswersTheRefusalOfADeleteInsteadOfAPlan(): void {
		$gateway = $this->readOnlyGateway();
		$gateway->method('boardCardCount')->willReturn(1);

		$result = $this->registry($gateway)->call('deck_delete_board', ['boardId' => 1], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame('The board has 1 card; move or delete it first.', $result['content'][0]['text']);
	}

	public function testRefusalExceptionIsWhatTheDeleteToolsRaise(): void {
		self::assertSame('Only the owner of a board can delete it.', DeckRefusalException::boardNotOwned()->getMessage());
	}

	private function registry(DeckGatewayInterface $gateway): ToolRegistry {
		$policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
		foreach (GrantPolicy::CATALOG['deck'] as $operation) {
			$policy->setGrant('alice', 'deck', $operation, true);
		}
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice Silva');
		$users->method('get')->willReturn($user);

		return new ToolRegistry([$this->moduleWith($gateway, $users)], $policy, $apps, $users, $this->createMock(LoggerInterface::class));
	}
}
