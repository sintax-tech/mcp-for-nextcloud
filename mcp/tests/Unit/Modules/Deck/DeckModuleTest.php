<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use InvalidArgumentException;
use OCA\Mcp\Modules\Deck\DeckModule;
use OCA\Mcp\Modules\Deck\Tools\CreateBoard;
use OCA\Mcp\Modules\Deck\Tools\CreateCard;
use OCA\Mcp\Modules\Deck\Tools\CreateStack;
use OCA\Mcp\Modules\Deck\Tools\DeleteBoard;
use OCA\Mcp\Modules\Deck\Tools\DeleteCard;
use OCA\Mcp\Modules\Deck\Tools\DeleteStack;
use OCA\Mcp\Modules\Deck\Tools\EditCard;
use OCA\Mcp\Modules\Deck\Tools\FollowupCards;
use OCA\Mcp\Modules\Deck\Tools\ListBoards;
use OCA\Mcp\Modules\Deck\Tools\ListCards;
use OCA\Mcp\Modules\Deck\Tools\ListStacks;
use OCA\Mcp\Modules\Deck\Tools\MoveCard;
use OCA\Mcp\Modules\Deck\Tools\ReadCard;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;
require_once __DIR__ . '/../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers what the registry reads from the module: the tool list, the schemas it validates arguments
 * with, and the routing of a call to its handler.
 */

final class DeckModuleTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
    use \OCA\Mcp\Tests\Support\Deck\DeckNativeFixtures;
    private IAppManager $appManager;
    private DeckModule $module;
    /**
     * @param ContainerInterface $container Container the module resolves the Deck through.
     * @param IAppManager $appManager App availability check.
     * @param LoggerInterface $logger Logger under test.
     * @param \OCP\IUserManager|null $users Account lookup of the module, null to leave it out.
     * @return DeckModule Module wired with the collaborators every card payload needs.
     */
    private function module(
        ContainerInterface $container,
        IAppManager $appManager,
        LoggerInterface $logger,
        ?\OCP\IUserManager $users = null,
    ): DeckModule {
        return $this->deckModuleFixture(
            $this->nativeDeck($container, $this->createMock(ITimeFactory::class), session: $this->deckSession()),
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
        $schema = $this->definitionOf(CreateCard::TOOL)['inputSchema'];
        self::assertSame(
            ['pedro'],
            \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator::validate($schema, ['stackId' => 10, 'title' => 'New', 'assignees' => ['pedro']])['assignees'],
        );
        self::assertArrayNotHasKey('assignees', $this->definitionOf(EditCard::TOOL)['inputSchema']['properties']);
        self::assertStringContainsString('assignments', implode(' ', $this->module->guideNotes()));
        foreach ([[''], [123], 'pedro', array_fill(0, 101, 'pedro')] as $value) {
            try {
                \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator::validate($schema, ['stackId' => 10, 'title' => 'New', 'assignees' => $value]);
                self::fail('accepted malformed assignees');
            } catch (\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException $e) {
                self::assertStringStartsWith('assignees', $e->details()['field']);
            }
        }
    }

    /** Editing accepts `assign` / `unassign` (at most 100 each) and the guide no longer says it cannot. */
    public function testEditDeclaresAssignAndUnassign(): void {
        $definition = $this->definitionOf(EditCard::TOOL);
        $schema = $definition['inputSchema'];
        foreach (['assign', 'unassign'] as $field) {
            self::assertSame('array', $schema['properties'][$field]['type']);
            self::assertSame(100, $schema['properties'][$field]['maxItems']);
            self::assertSame(
                ['pedro'],
                \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator::validate($schema, ['cardId' => 7, $field => ['pedro']])[$field],
            );
            foreach ([[''], [123], 'pedro', array_fill(0, 101, 'pedro')] as $value) {
                try {
                    \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator::validate($schema, ['cardId' => 7, $field => $value]);
                    self::fail('accepted malformed ' . $field);
                } catch (\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException $e) {
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

    public function testDeclaresTheThirteenToolsInTheDeclaredOrder(): void {
        self::assertSame(
            [
                'deck_list_boards',
                'deck_list_stacks',
                'deck_list_cards',
                'deck_read_card',
                'deck_create_card',
                'deck_edit_card',
                'deck_move_card',
                'deck_delete_card',
                'deck_followup_cards',
                'deck_create_board',
                'deck_create_stack',
                'deck_delete_stack',
                'deck_delete_board',
            ],
            array_column($this->module->definitions(), 'name'),
        );
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
            'list boards' => [ListBoards::TOOL, 'read'],
            'list stacks' => [ListStacks::TOOL, 'read'],
            'list cards' => [ListCards::TOOL, 'read'],
            'read card' => [ReadCard::TOOL, 'read'],
            'create card' => [CreateCard::TOOL, 'create'],
            'edit card' => [EditCard::TOOL, 'edit'],
            'move card' => [MoveCard::TOOL, 'move'],
            'delete card' => [DeleteCard::TOOL, 'delete'],
            'follow up cards' => [FollowupCards::TOOL, 'read'],
            'create board' => [CreateBoard::TOOL, 'create'],
            'create stack' => [CreateStack::TOOL, 'create'],
            'delete stack' => [DeleteStack::TOOL, 'delete'],
            'delete board' => [DeleteBoard::TOOL, 'delete'],
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
        $schema = $this->definitionOf(ListBoards::TOOL)['inputSchema'];
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
            'list stacks' => [ListStacks::TOOL, ['boardId']],
            'list cards' => [ListCards::TOOL, ['stackId']],
            'read card' => [ReadCard::TOOL, ['cardId']],
            'create card' => [CreateCard::TOOL, ['stackId', 'title']],
            'edit card' => [EditCard::TOOL, ['cardId']],
            'move card' => [MoveCard::TOOL, ['cardId', 'stackId']],
            'delete card' => [DeleteCard::TOOL, ['cardId']],
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
        $definition = $this->definitionOf(DeleteCard::TOOL);
        self::assertArrayNotHasKey('confirm', $definition['inputSchema']['properties'] ?? []);
        $published = \OCA\Mcp\Mcp\Confirmation\WriteGate::publish($definition);
        $confirm = $published['inputSchema']['properties']['confirm'];
        self::assertSame('boolean', $confirm['type']);
        self::assertArrayNotHasKey('const', $confirm);
    }

    public function testEveryWriteToolOffersConfirmSharedAndWarnsAboutItInTheDescription(): void {
        $writeTools = [CreateCard::TOOL, EditCard::TOOL, MoveCard::TOOL, DeleteCard::TOOL];
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
            ListBoards::TOOL,
            ListStacks::TOOL,
            ListCards::TOOL,
            FollowupCards::TOOL,
            ReadCard::TOOL,
        ];
        foreach ($readTools as $name) {
            self::assertStringNotContainsString(
                'confirm_shared',
                (string) json_encode($this->definitionOf($name)['inputSchema']['properties']),
                $name,
            );
            self::assertStringNotContainsString('confirm_shared', $this->definitionOf($name)['description'], $name);
        }
    }

    public function testEditLeavesDuedateWithoutDefaultSoAbsenceIsDistinctFromNull(): void {
        $properties = $this->definitionOf(EditCard::TOOL)['inputSchema']['properties'];
        self::assertArrayNotHasKey('default', $properties['duedate']);
        self::assertSame(['string', 'null'], $properties['duedate']['type']);
    }

    public function testListCardsCarriesThePaginationBounds(): void {
        $properties = $this->definitionOf(ListCards::TOOL)['inputSchema']['properties'];
        self::assertSame(1, $properties['limit']['minimum']);
        self::assertSame(200, $properties['limit']['maximum']);
        self::assertSame(50, $properties['limit']['default']);
        self::assertSame(0, $properties['offset']['minimum']);
    }

    public function testFollowupCardsTakesEveryArgumentAsOptionalWithTheLateDefault(): void {
        $schema = $this->definitionOf(FollowupCards::TOOL)['inputSchema'];
        self::assertArrayNotHasKey('required', $schema);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['overdue', 'open', 'done', 'all'], $schema['properties']['status']['enum']);
        self::assertSame('overdue', $schema['properties']['status']['default']);
        self::assertSame(1, $schema['properties']['limit']['minimum']);
        self::assertSame(FollowupCards::MAX_LIMIT, $schema['properties']['limit']['maximum']);
        self::assertSame(FollowupCards::DEFAULT_LIMIT, $schema['properties']['limit']['default']);
    }

    public function testTitleIsBoundedByTheDeckColumnWidth(): void {
        $title = $this->definitionOf(CreateCard::TOOL)['inputSchema']['properties']['title'];
        self::assertSame(1, $title['minLength']);
        self::assertSame(255, $title['maxLength']);
    }

    public function testUnknownToolIsRefusedBeforeTouchingDeck(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorUnknownTool());
        $this->module->call('deck_everything', [], 'alice');
    }

    public function testDeckAppIsRecheckedEvenThoughTheRegistryFiltersFirst(): void {
        $user = $this->createMock(\OCP\IUser::class);
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        $appManager = $this->createMock(IAppManager::class);
        $appManager->expects(self::once())->method('isEnabledForUser')->with('deck', self::identicalTo($user))->willReturn(false);
        $module = $this->module(
            $this->createMock(ContainerInterface::class),
            $appManager,
            $this->createMock(LoggerInterface::class),
            $users,
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorDeckAppUnavailable());
        $module->call(ListBoards::TOOL, [], 'alice');
    }

    /** A Deck older than the supported minimum is refused by the module too, not only hidden by the registry. */
    public function testADeckOlderThanTheSupportedMinimumIsRefusedByTheModule(): void {
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->with('alice')->willReturn($this->createMock(\OCP\IUser::class));
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn(true);
        $appManager->method('getAppVersion')->with('deck')->willReturn('1.14.11');
        $module = $this->module(
            $this->createMock(ContainerInterface::class),
            $appManager,
            $this->createMock(LoggerInterface::class),
            $users,
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorDeckAppUnavailable());
        $module->call(ListBoards::TOOL, [], 'alice');
    }

    public function testEveryDefinitionIsHandled(): void {
        foreach ($this->module->definitions() as $definition) {
            $appManager = $this->createMock(IAppManager::class);
            $appManager->method('isEnabledForUser')->willReturn(false);
            $module = $this->module($this->createMock(ContainerInterface::class), $appManager, $this->createMock(LoggerInterface::class));
            // Reaching the app check proves the name was recognised by the router.
            try {
                $module->call($definition['name'], [], 'alice');
                self::fail('Expected the app check to refuse the call.');
            } catch (InvalidArgumentException $e) {
                self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorDeckAppUnavailable(), $e->getMessage());
            }
        }
    }
}
