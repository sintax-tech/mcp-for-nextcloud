<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use InvalidArgumentException;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCA\Mcp\Tools\Deck\Handler\CreateCardHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteCardHandler;
use OCA\Mcp\Tools\Deck\Handler\EditCardHandler;
use OCA\Mcp\Tools\Deck\Handler\FollowupCardsHandler;
use OCA\Mcp\Tools\Deck\Handler\ListBoardsHandler;
use OCA\Mcp\Tools\Deck\Handler\ListCardsHandler;
use OCA\Mcp\Tools\Deck\Handler\ListStacksHandler;
use OCA\Mcp\Tools\Deck\Handler\MoveCardHandler;
use OCA\Mcp\Tools\Deck\Handler\ReadCardHandler;
use OCA\Mcp\Tools\ToolModule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Covers what the registry reads from the module: the tool list, the schemas it validates arguments
 * with, and the routing of a call to its handler.
 */
final class DeckToolModuleTest extends TestCase {
	use DeckTestHelpers;

	private IAppManager $appManager;

	private DeckToolModule $module;

	/**
	 * @param ContainerInterface $container Container the module resolves the Deck through.
	 * @param IAppManager $appManager App availability check.
	 * @param LoggerInterface $logger Logger under test.
	 * @param \OCP\IUserManager|null $users Account lookup of the module, null to leave it out.
	 * @return DeckToolModule Module wired with the collaborators every card payload needs.
	 */
	private function module(
		ContainerInterface $container,
		IAppManager $appManager,
		LoggerInterface $logger,
		?\OCP\IUserManager $users = null,
	): DeckToolModule {
		return new DeckToolModule(
			$container,
			$appManager,
			$logger,
			$this->createMock(IURLGenerator::class),
			$this->createMock(ITimeFactory::class),
			$users,
		);
	}

	protected function setUp(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \LogicException('The Deck app must not be resolved at construction time.'));
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->module = $this->module($container, $this->appManager, $this->createMock(LoggerInterface::class));
	}

	/** Only creation accepts assignments; schemas and guide expose the supported behavior. */
	public function testCreationDeclaresAssigneesAndRejectsMalformedAccountIds(): void {
		$schema = $this->definitionOf(CreateCardHandler::TOOL)['inputSchema'];
		self::assertSame(['pedro'], \OCA\Mcp\Tools\ArgumentValidator::validate($schema, ['stackId' => 10, 'title' => 'New', 'assignees' => ['pedro']])['assignees']);
		self::assertArrayNotHasKey('assignees', $this->definitionOf(EditCardHandler::TOOL)['inputSchema']['properties']);
		self::assertStringContainsString('assignments', implode(' ', $this->module->guideNotes()));
		foreach ([[''], [123], 'pedro', array_fill(0, 101, 'pedro')] as $value) {
			try {
				\OCA\Mcp\Tools\ArgumentValidator::validate($schema, ['stackId' => 10, 'title' => 'New', 'assignees' => $value]);
				self::fail('accepted malformed assignees');
			} catch (\OCA\Mcp\Tools\ArgumentValidationException $e) {
				self::assertStringStartsWith('assignees', $e->details()['field']);
			}
		}
	}

	/** Editing accepts `assign` / `unassign` (at most 100 each) and the guide no longer says it cannot. */
	public function testEditDeclaresAssignAndUnassign(): void {
		$definition = $this->definitionOf(EditCardHandler::TOOL);
		$schema = $definition['inputSchema'];
		foreach (['assign', 'unassign'] as $field) {
			self::assertSame('array', $schema['properties'][$field]['type']);
			self::assertSame(100, $schema['properties'][$field]['maxItems']);
			self::assertSame(['pedro'], \OCA\Mcp\Tools\ArgumentValidator::validate($schema, ['cardId' => 7, $field => ['pedro']])[$field]);
			foreach ([[''], [123], 'pedro', array_fill(0, 101, 'pedro')] as $value) {
				try {
					\OCA\Mcp\Tools\ArgumentValidator::validate($schema, ['cardId' => 7, $field => $value]);
					self::fail('accepted malformed ' . $field);
				} catch (\OCA\Mcp\Tools\ArgumentValidationException $e) {
					self::assertStringStartsWith($field, $e->details()['field']);
				}
			}
		}
		self::assertStringNotContainsString('not supported', implode(' ', $this->module->guideNotes()));
		self::assertStringContainsString('assign', $definition['description']);
		self::assertStringContainsString('unassign', $definition['description']);
	}

	public function testImplementsTheModuleContract(): void {
		self::assertInstanceOf(ToolModule::class, $this->module);
	}

	public function testDeclaresTheNineToolsInTheDeclaredOrder(): void {
		self::assertSame([
			'deck_list_boards',
			'deck_list_stacks',
			'deck_list_cards',
			'deck_read_card',
			'deck_create_card',
			'deck_edit_card',
			'deck_move_card',
			'deck_delete_card',
			'deck_followup_cards',
		], array_column($this->module->definitions(), 'name'));
	}

	public function testEveryToolBelongsToTheDeckModuleAndToTheDeckApp(): void {
		foreach ($this->module->definitions() as $definition) {
			self::assertSame('deck', $definition['module'], $definition['name']);
			self::assertSame('deck', $definition['app'], $definition['name']);
			self::assertNotSame('', $definition['description'], $definition['name']);
		}
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function operationProvider(): array {
		return [
			'list boards' => [ListBoardsHandler::TOOL, 'read'],
			'list stacks' => [ListStacksHandler::TOOL, 'read'],
			'list cards' => [ListCardsHandler::TOOL, 'read'],
			'read card' => [ReadCardHandler::TOOL, 'read'],
			'create card' => [CreateCardHandler::TOOL, 'create'],
			'edit card' => [EditCardHandler::TOOL, 'edit'],
			'move card' => [MoveCardHandler::TOOL, 'move'],
			'delete card' => [DeleteCardHandler::TOOL, 'delete'],
			'follow up cards' => [FollowupCardsHandler::TOOL, 'read'],
		];
	}

	/**
	 * @param string $name Tool name.
	 * @param string $operation Grant operation the tool needs.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('operationProvider')]
	public function testToolDeclaresItsGrantOperation(string $name, string $operation): void {
		$definition = $this->definitionOf($name);

		self::assertSame($operation, $definition['operation']);
	}

	/**
	 * @param string $name Tool name.
	 * @return array<string, mixed> Definition of that tool.
	 */
	private function definitionOf(string $name): array {
		foreach ($this->module->definitions() as $definition) {
			if ($definition['name'] === $name) {
				return $definition;
			}
		}

		self::fail('Tool not declared: ' . $name);
	}

	public function testSchemaOfListBoardsIsAnObjectWithoutProperties(): void {
		$schema = $this->definitionOf(ListBoardsHandler::TOOL)['inputSchema'];

		self::assertSame('object', $schema['type']);
		self::assertFalse($schema['additionalProperties']);
		self::assertInstanceOf(stdClass::class, $schema['properties']);
		self::assertSame('{}', json_encode($schema['properties']));
	}

	/**
	 * @return array<string, array{string, list<string>}>
	 */
	public static function requiredProvider(): array {
		return [
			'list stacks' => [ListStacksHandler::TOOL, ['boardId']],
			'list cards' => [ListCardsHandler::TOOL, ['stackId']],
			'read card' => [ReadCardHandler::TOOL, ['cardId']],
			'create card' => [CreateCardHandler::TOOL, ['stackId', 'title']],
			'edit card' => [EditCardHandler::TOOL, ['cardId']],
			'move card' => [MoveCardHandler::TOOL, ['cardId', 'stackId']],
			'delete card' => [DeleteCardHandler::TOOL, ['cardId']],
		];
	}

	/**
	 * @param string $name Tool name.
	 * @param list<string> $required Properties the registry demands.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('requiredProvider')]
	public function testSchemaRequiresOnlyWhatTheToolNeeds(string $name, array $required): void {
		$schema = $this->definitionOf($name)['inputSchema'];

		self::assertSame($required, $schema['required']);
		self::assertFalse($schema['additionalProperties']);
	}

	public function testDeleteDefersConfirmToTheRegistryGate(): void {
		$definition = $this->definitionOf(DeleteCardHandler::TOOL);
		self::assertArrayNotHasKey('confirm', $definition['inputSchema']['properties'] ?? []);

		$published = \OCA\Mcp\Tools\WriteGate::publish($definition);
		$confirm = $published['inputSchema']['properties']['confirm'];
		self::assertSame('boolean', $confirm['type']);
		self::assertArrayNotHasKey('const', $confirm);
	}

	public function testEveryWriteToolOffersConfirmSharedAndWarnsAboutItInTheDescription(): void {
		$writeTools = [
			CreateCardHandler::TOOL,
			EditCardHandler::TOOL,
			MoveCardHandler::TOOL,
			DeleteCardHandler::TOOL,
		];

		foreach ($writeTools as $name) {
			$definition = $this->definitionOf($name);
			$property = $definition['inputSchema']['properties']['confirm_shared'] ?? null;

			self::assertIsArray($property, $name);
			self::assertSame('boolean', $property['type'], $name);
			self::assertNotContains('confirm_shared', $definition['inputSchema']['required'], $name);
			self::assertStringContainsString('confirm_shared: true', $definition['description'], $name);
		}
	}

	public function testReadToolsDoNotDeclareConfirmShared(): void {
		$readTools = [
			ListBoardsHandler::TOOL,
			ListStacksHandler::TOOL,
			ListCardsHandler::TOOL,
			FollowupCardsHandler::TOOL,
			ReadCardHandler::TOOL,
		];

		foreach ($readTools as $name) {
			self::assertStringNotContainsString(
				'confirm_shared',
				(string)json_encode($this->definitionOf($name)['inputSchema']['properties']),
				$name,
			);
			self::assertStringNotContainsString('confirm_shared', $this->definitionOf($name)['description'], $name);
		}
	}

	public function testEditLeavesDuedateWithoutDefaultSoAbsenceIsDistinctFromNull(): void {
		$properties = $this->definitionOf(EditCardHandler::TOOL)['inputSchema']['properties'];

		self::assertArrayNotHasKey('default', $properties['duedate']);
		self::assertSame(['string', 'null'], $properties['duedate']['type']);
	}

	public function testListCardsCarriesThePaginationBounds(): void {
		$properties = $this->definitionOf(ListCardsHandler::TOOL)['inputSchema']['properties'];

		self::assertSame(1, $properties['limit']['minimum']);
		self::assertSame(200, $properties['limit']['maximum']);
		self::assertSame(50, $properties['limit']['default']);
		self::assertSame(0, $properties['offset']['minimum']);
	}

	public function testFollowupCardsTakesEveryArgumentAsOptionalWithTheLateDefault(): void {
		$schema = $this->definitionOf(FollowupCardsHandler::TOOL)['inputSchema'];

		self::assertArrayNotHasKey('required', $schema);
		self::assertFalse($schema['additionalProperties']);
		self::assertSame(['overdue', 'open', 'done', 'all'], $schema['properties']['status']['enum']);
		self::assertSame('overdue', $schema['properties']['status']['default']);
		self::assertSame(1, $schema['properties']['limit']['minimum']);
		self::assertSame(FollowupCardsHandler::MAX_LIMIT, $schema['properties']['limit']['maximum']);
		self::assertSame(FollowupCardsHandler::DEFAULT_LIMIT, $schema['properties']['limit']['default']);
	}

	public function testTitleIsBoundedByTheDeckColumnWidth(): void {
		$title = $this->definitionOf(CreateCardHandler::TOOL)['inputSchema']['properties']['title'];

		self::assertSame(1, $title['minLength']);
		self::assertSame(255, $title['maxLength']);
	}

	public function testUnknownToolIsRefusedBeforeTouchingDeck(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorUnknownTool());

		$this->module->call('deck_everything', [], 'alice');
	}

	public function testDeckAppIsRecheckedEvenThoughTheRegistryFiltersFirst(): void {
		$user = $this->createMock(\OCP\IUser::class);
		$users = $this->createMock(\OCP\IUserManager::class);
		$users->method('get')->with('alice')->willReturn($user);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects(self::once())
			->method('isEnabledForUser')
			->with('deck', self::identicalTo($user))
			->willReturn(false);
		$module = $this->module(
			$this->createMock(ContainerInterface::class),
			$appManager,
			$this->createMock(LoggerInterface::class),
			$users,
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorDeckAppUnavailable());

		$module->call(ListBoardsHandler::TOOL, [], 'alice');
	}

	public function testEveryDefinitionIsHandled(): void {
		foreach ($this->module->definitions() as $definition) {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('isEnabledForUser')->willReturn(false);
			$module = $this->module(
				$this->createMock(ContainerInterface::class),
				$appManager,
				$this->createMock(LoggerInterface::class),
			);

			// Reaching the app check proves the name was recognised by the router.
			try {
				$module->call($definition['name'], [], 'alice');
				self::fail('Expected the app check to refuse the call.');
			} catch (InvalidArgumentException $e) {
				self::assertSame(DeckMessages::errorDeckAppUnavailable(), $e->getMessage());
			}
		}
	}
}
