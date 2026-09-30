<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use InvalidArgumentException;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCA\Mcp\Tools\Deck\Handler\CreateCardHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteCardHandler;
use OCA\Mcp\Tools\Deck\Handler\EditCardHandler;
use OCA\Mcp\Tools\Deck\Handler\ListBoardsHandler;
use OCA\Mcp\Tools\Deck\Handler\ListCardsHandler;
use OCA\Mcp\Tools\Deck\Handler\ListStacksHandler;
use OCA\Mcp\Tools\Deck\Handler\MoveCardHandler;
use OCA\Mcp\Tools\Deck\Handler\ReadCardHandler;
use OCA\Mcp\Tools\ToolModule;
use OCP\App\IAppManager;
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

	protected function setUp(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \LogicException('The Deck app must not be resolved at construction time.'));
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->module = new DeckToolModule($container, $this->appManager, $this->createMock(LoggerInterface::class));
	}

	public function testImplementsTheModuleContract(): void {
		self::assertInstanceOf(ToolModule::class, $this->module);
	}

	public function testDeclaresTheEightContractedTools(): void {
		self::assertSame([
			'deck_list_boards',
			'deck_list_stacks',
			'deck_list_cards',
			'deck_read_card',
			'deck_create_card',
			'deck_edit_card',
			'deck_move_card',
			'deck_delete_card',
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
			'delete card' => [DeleteCardHandler::TOOL, ['cardId', 'confirm']],
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

	public function testDeleteRequiresConfirmTrueSoTheRegistryBlocksAnythingElse(): void {
		$confirm = $this->definitionOf(DeleteCardHandler::TOOL)['inputSchema']['properties']['confirm'];

		self::assertTrue($confirm['const']);
		self::assertSame('boolean', $confirm['type']);
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

	public function testTitleIsBoundedByTheDeckColumnWidth(): void {
		$title = $this->definitionOf(CreateCardHandler::TOOL)['inputSchema']['properties']['title'];

		self::assertSame(1, $title['minLength']);
		self::assertSame(255, $title['maxLength']);
	}

	public function testUnknownToolIsRefusedBeforeTouchingDeck(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::ERROR_UNKNOWN_TOOL);

		$this->module->call('deck_everything', [], 'alice');
	}

	public function testDeckAppIsRecheckedEvenThoughTheRegistryFiltersFirst(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects(self::once())
			->method('isEnabledForUser')
			->with('deck', 'alice')
			->willReturn(false);
		$module = new DeckToolModule(
			$this->createMock(ContainerInterface::class),
			$appManager,
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::ERROR_DECK_APP_UNAVAILABLE);

		$module->call(ListBoardsHandler::TOOL, [], 'alice');
	}

	public function testEveryDefinitionIsHandled(): void {
		foreach ($this->module->definitions() as $definition) {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('isEnabledForUser')->willReturn(false);
			$module = new DeckToolModule(
				$this->createMock(ContainerInterface::class),
				$appManager,
				$this->createMock(LoggerInterface::class),
			);

			// Reaching the app check proves the name was recognised by the router.
			try {
				$module->call($definition['name'], [], 'alice');
				self::fail('Expected the app check to refuse the call.');
			} catch (InvalidArgumentException $e) {
				self::assertSame(DeckMessages::ERROR_DECK_APP_UNAVAILABLE, $e->getMessage());
			}
		}
	}
}
