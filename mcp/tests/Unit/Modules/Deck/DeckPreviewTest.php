<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\DeckModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
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
require_once __DIR__ . '/../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers the content of the Deck plans: what each write would do, read through the capability, and the
 * guarantee that a plan never reaches a writing method of the capability.
 */

final class DeckPreviewTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
    /**
     * Gateway double whose writing methods must never be called, with boards and lists to name.
     *
     * Stack 10 lives in `Pessoal` (alice), stack 20 in `Equipe` (pedro), so a plan can show a shared end.
     *
     * @return DeckDoubles
     */
    private function readOnlyCapabilities(): DeckDoubles {
        $capabilities = $this->deckDoubles();
        foreach ([
            'createCard',
            'updateCard',
            'moveCard',
            'deleteCard',
            'createBoard',
            'createStack',
            'deleteEmptyStack',
            'deleteEmptyBoard',
            'assignCardUser',
            'unassignCardUser',
        ] as $write) {
            (match ($write) {
                'listBoards' => $capabilities->boards,
                'listStacks' => $capabilities->stacks,
                'listCards' => $capabilities->cards,
                'findCard' => $capabilities->cards,
                'cardBoardId' => $capabilities->cards,
                'createCard' => $capabilities->writer,
                'updateCard' => $capabilities->writer,
                'moveCard' => $capabilities->writer,
                'deleteCard' => $capabilities->writer,
                'cardState' => $capabilities->state,
                'findCreatedCard' => $capabilities->state,
                'validateAssignees' => $capabilities->assignments,
                'assignCardUser' => $capabilities->assignments,
                'unassignCardUser' => $capabilities->assignments,
                'cardAssignments' => $capabilities->assignments,
                'cardOwnership' => $capabilities->ownership,
                'stackOwnership' => $capabilities->ownership,
                'boardOwnership' => $capabilities->ownership,
                'createBoard' => $capabilities->boardCreator,
                'createStack' => $capabilities->stackCreator,
                'stacksOf' => $capabilities->structureState,
                'deletedStack' => $capabilities->structureState,
                'ownedBoards' => $capabilities->structureState,
                'stackCardCount' => $capabilities->counts,
                'boardCardCount' => $capabilities->counts,
                'deleteEmptyStack' => $capabilities->deletion,
                'deleteEmptyBoard' => $capabilities->deletion,
                'followupCards' => $capabilities->followup,
            })->expects(self::never())->method($write);
        }
        $capabilities->boards->method('listBoards')->willReturn([new Board(['id' => 1, 'title' => 'Pessoal']), new Board(['id' => 2, 'title' => 'Equipe'])]);
        $capabilities->stacks->method('listStacks')->willReturnCallback(static fn(string $user, int $board): array => $board === 1 ? [new Stack(['id' => 10, 'title' => 'A fazer'])] : [new Stack(['id' => 20, 'title' => 'Feito'])]);
        $capabilities->ownership->method('stackOwnership')->willReturnCallback(fn(string $user, int $stack): array => $stack === 20 ? $this->ownershipOf('pedro', 'Equipe') : $this->ownershipOf('alice'));
        $capabilities->cards->method('findCard')->willReturn($this->card([
            'id' => 7,
            'stackId' => 10,
            'title' => 'Antes',
            'description' => 'texto',
            'lastModified' => 1700000123,
        ]));
        return $capabilities;
    }

    private function moduleWith(
        DeckDoubles $capabilities,
        ?IUserManager $users = null,
        ?IConfig $config = null,
    ): DeckModule {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $module = $this->deckModuleFixture(
            $capabilities,
            $apps,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(ITimeFactory::class),
            $users,
            $config,
        );
        // The capability is built lazily from the Deck container; the test hands its double in its place.
        return $module;
    }

    /** Explicit assignees are validated during preview and their display names are escaped by the renderer. */
    public function testCreatePlanIncludesAssignees(): void {
        $capabilities = $this->readOnlyCapabilities();
        $capabilities->assignments->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro'])->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro **Admin**']]);
        $capabilities->assignments->expects(self::never())->method('assignCardUser');
        $module = $this->moduleWith($capabilities);
        $plan = $module->preview('deck_create_card', ['stackId' => 10, 'title' => 'New', 'assignees' => ['pedro']], 'alice');
        self::assertSame('Pedro **Admin**', $plan['card']['assignees'][0]['displayName']);
        self::assertStringContainsString('Pedro \*\*Admin\*\*', $module->renderPlan('deck_create_card', $plan));
    }

    public function testCreatePlanShowsTheCardAndItsDestination(): void {
        $capabilities = $this->readOnlyCapabilities();
        $capabilities->cards->expects(self::never())->method('findCard');
        $plan = $this->moduleWith($capabilities)->preview(
            'deck_create_card',
            ['stackId' => 10, 'title' => 'Novo', 'description' => 'corpo', 'duedate' => '2026-10-01'],
            'alice',
        );
        self::assertSame('deck_create_card', $plan['action']);
        self::assertSame(
            ['title' => 'Novo', 'description' => 'corpo', 'dueDate' => '2026-10-01', 'owner' => 'alice'],
            $plan['card'],
        );
        self::assertSame(10, $plan['destination']['stackId']);
        self::assertSame('A fazer', $plan['destination']['list']);
        self::assertSame('Pessoal', $plan['destination']['board']);
        self::assertFalse($plan['destination']['shared']);
        self::assertSame([], $plan['shared']);
        self::assertTrue($plan['recoverable']);
    }

    public function testCreatePlanOnSomebodyElsesBoardListsItAsShared(): void {
        $plan = $this->moduleWith($this->readOnlyCapabilities())->preview('deck_create_card', ['stackId' => 20, 'title' => 'Novo'], 'alice');
        self::assertTrue($plan['destination']['shared']);
        self::assertSame('pedro', $plan['destination']['owner']);
        self::assertSame([$plan['destination']], $plan['shared']);
    }

    public function testEditPlanShowsBeforeAfterAndOnlyTheChangedFields(): void {
        $capabilities = $this->readOnlyCapabilities();
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('alice'));
        $plan = $this->moduleWith($capabilities)->preview('deck_edit_card', ['cardId' => 7, 'title' => 'Depois', 'lastModified' => 5], 'alice');
        self::assertSame(7, $plan['card']['id']);
        self::assertSame(['title' => 'Antes', 'description' => 'texto', 'duedate' => null], $plan['card']['before']);
        self::assertSame(['title' => 'Depois', 'description' => 'texto', 'duedate' => null], $plan['card']['after']);
        self::assertSame(['title'], $plan['changed']);
        self::assertSame(['current' => 1700000123, 'sent' => 5], $plan['lastModified']);
        self::assertSame([], $plan['shared']);
        self::assertTrue($plan['recoverable']);
    }

    /** The stored instant and the day the call sends are the same date for the user, so nothing changes. */
    public function testEditPlanDoesNotReportTheSameDueDateAsAChange(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->writer->expects(self::never())->method('updateCard');
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('alice'));
        // Deck keeps the date in UTC: local midnight of 1 October in São Paulo is 03:00 UTC.
        $capabilities->cards->method('findCard')->willReturn($this->card([
            'id' => 7,
            'title' => 'Antes',
            'duedate' => new \DateTime('2026-10-01 03:00:00', new \DateTimeZone('UTC')),
        ]));
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('America/Sao_Paulo');
        $module = $this->moduleWith($capabilities, null, $config);
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
     * @return DeckDoubles Gateway whose writes must never run.
     */
    private function capabilitiesWithAssignedCard(array $assigned): DeckDoubles {
        $capabilities = $this->deckDoubles();
        foreach (['createCard', 'updateCard', 'moveCard', 'deleteCard', 'assignCardUser', 'unassignCardUser'] as $write) {
            (match ($write) {
                'listBoards' => $capabilities->boards,
                'listStacks' => $capabilities->stacks,
                'listCards' => $capabilities->cards,
                'findCard' => $capabilities->cards,
                'cardBoardId' => $capabilities->cards,
                'createCard' => $capabilities->writer,
                'updateCard' => $capabilities->writer,
                'moveCard' => $capabilities->writer,
                'deleteCard' => $capabilities->writer,
                'cardState' => $capabilities->state,
                'findCreatedCard' => $capabilities->state,
                'validateAssignees' => $capabilities->assignments,
                'assignCardUser' => $capabilities->assignments,
                'unassignCardUser' => $capabilities->assignments,
                'cardAssignments' => $capabilities->assignments,
                'cardOwnership' => $capabilities->ownership,
                'stackOwnership' => $capabilities->ownership,
                'boardOwnership' => $capabilities->ownership,
                'createBoard' => $capabilities->boardCreator,
                'createStack' => $capabilities->stackCreator,
                'stacksOf' => $capabilities->structureState,
                'deletedStack' => $capabilities->structureState,
                'ownedBoards' => $capabilities->structureState,
                'stackCardCount' => $capabilities->counts,
                'boardCardCount' => $capabilities->counts,
                'deleteEmptyStack' => $capabilities->deletion,
                'deleteEmptyBoard' => $capabilities->deletion,
                'followupCards' => $capabilities->followup,
            })->expects(self::never())->method($write);
        }
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('alice'));
        $capabilities->cards->method('findCard')->willReturn($this->card([
            'id' => 7,
            'stackId' => 10,
            'title' => 'Antes',
            'assignedUsers' => array_map(
                static fn(string $uid): Assignment => new Assignment(['cardId' => 7, 'participant' => $uid, 'type' => 0]),
                $assigned,
            ),
        ]));
        return $capabilities;
    }

    public function testEditPlanListsTheFinalAssigneesWhoJoinsAndWhoLeaves(): void {
        $capabilities = $this->capabilitiesWithAssignedCard(['ana', 'luis']);
        $capabilities->assignments->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro'])->willReturn([['uid' => 'pedro', 'displayName' => 'Paulo Exemplo']]);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): IUser {
            $user = $this->createMock(IUser::class);
            $user->method('getDisplayName')->willReturn(['ana' => 'Ana Souza', 'luis' => 'Luís Lima'][$uid] ?? $uid);
            return $user;
        });
        $module = $this->moduleWith($capabilities, $users);
        $plan = $module->preview('deck_edit_card', ['cardId' => 7, 'assign' => ['pedro'], 'unassign' => ['luis', 'nunca']], 'alice');
        self::assertSame(['assignees'], $plan['changed']);
        self::assertSame(
            [['uid' => 'ana', 'displayName' => 'Ana Souza'], ['uid' => 'luis', 'displayName' => 'Luís Lima']],
            $plan['assignees']['before'],
        );
        self::assertSame(
            [['uid' => 'ana', 'displayName' => 'Ana Souza'], ['uid' => 'pedro', 'displayName' => 'Paulo Exemplo']],
            $plan['assignees']['after'],
        );
        self::assertSame([['uid' => 'pedro', 'displayName' => 'Paulo Exemplo']], $plan['assignees']['added']);
        self::assertSame([['uid' => 'luis', 'displayName' => 'Luís Lima']], $plan['assignees']['removed']);
        self::assertSame(['nunca'], $plan['assignees']['notAssigned']);
        self::assertSame([], $plan['assignees']['alreadyAssigned']);
        $text = (string) $module->renderPlan('deck_edit_card', $plan);
        self::assertStringContainsString('- Assigned: Paulo Exemplo', $text);
        self::assertStringContainsString('- Unassigned: Luís Lima', $text);
        self::assertStringContainsString('- Assignees after the change: Ana Souza, Paulo Exemplo', $text);
        self::assertStringContainsString('- Ignored, not assigned to the card: nunca', $text);
    }

    public function testEditPlanRefusesAnAccountWithoutAccessBeforeAnythingIsWritten(): void {
        $capabilities = $this->capabilitiesWithAssignedCard([]);
        $capabilities->assignments->method('validateAssignees')->willThrowException(new \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException('Invalid argument: assignees', 'assignees', 'each account must exist and have access to the board'));
        $this->expectException(\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException::class);
        $this->moduleWith($capabilities)->preview('deck_edit_card', ['cardId' => 7, 'assign' => ['intruso']], 'alice');
    }

    public function testEditPlanRefusesAnAccountInBothLists(): void {
        $capabilities = $this->capabilitiesWithAssignedCard(['pedro']);
        $capabilities->cards->expects(self::never())->method('findCard');
        $this->expectException(\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException::class);
        $this->moduleWith($capabilities)->preview('deck_edit_card', ['cardId' => 7, 'assign' => ['pedro'], 'unassign' => ['pedro']], 'alice');
    }

    public function testEditPlanWithoutAssigneeArgumentsHasNoAssigneeSection(): void {
        $capabilities = $this->capabilitiesWithAssignedCard(['ana']);
        $plan = $this->moduleWith($capabilities)->preview('deck_edit_card', ['cardId' => 7, 'title' => 'Depois'], 'alice');
        self::assertArrayNotHasKey('assignees', $plan);
        self::assertSame(['title'], $plan['changed']);
    }

    public function testEditPlanWithAssignedAccountsIgnoredChangesNothing(): void {
        $capabilities = $this->capabilitiesWithAssignedCard(['ana']);
        $capabilities->assignments->expects(self::never())->method('validateAssignees');
        $module = $this->moduleWith($capabilities);
        $plan = $module->preview('deck_edit_card', ['cardId' => 7, 'assign' => ['ana']], 'alice');
        self::assertSame([], $plan['changed']);
        self::assertSame(['ana'], $plan['assignees']['alreadyAssigned']);
        self::assertStringContainsString(
            '- Ignored, already assigned to the card: ana',
            (string) $module->renderPlan('deck_edit_card', $plan),
        );
    }

    public function testEditPlanOnASharedBoardNamesItsOwner(): void {
        $capabilities = $this->readOnlyCapabilities();
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('pedro', 'Equipe'));
        $plan = $this->moduleWith($capabilities)->preview('deck_edit_card', ['cardId' => 7, 'description' => 'novo'], 'alice');
        self::assertSame(['description'], $plan['changed']);
        self::assertTrue($plan['board']['shared']);
        self::assertSame(
            [['board' => 'Equipe', 'owner' => 'pedro', 'ownerDisplayName' => 'Paulo Exemplo', 'shared' => true]],
            $plan['shared'],
        );
    }

    public function testMovePlanShowsOriginAndDestination(): void {
        $plan = $this->moduleWith($this->readOnlyCapabilities())->preview('deck_move_card', ['cardId' => 7, 'stackId' => 20, 'order' => 2], 'alice');
        self::assertSame(7, $plan['card']['id']);
        self::assertSame('Antes', $plan['card']['title']);
        self::assertSame(
            ['stackId' => 10, 'list' => 'A fazer', 'board' => 'Pessoal'],
            array_intersect_key($plan['origin'], array_flip(['stackId', 'list', 'board'])),
        );
        self::assertSame(
            ['stackId' => 20, 'list' => 'Feito', 'board' => 'Equipe'],
            array_intersect_key($plan['destination'], array_flip(['stackId', 'list', 'board'])),
        );
        self::assertSame(2, $plan['order']);
        self::assertFalse($plan['sameList']);
        self::assertSame([$plan['destination']], $plan['shared']);
        self::assertTrue($plan['recoverable']);
    }

    public function testMovePlanWithinTheSameList(): void {
        $plan = $this->moduleWith($this->readOnlyCapabilities())->preview('deck_move_card', ['cardId' => 7, 'stackId' => 10], 'alice');
        self::assertTrue($plan['sameList']);
        self::assertNull($plan['order']);
        self::assertSame([], $plan['shared']);
    }

    public function testDeletePlanSaysTheCardCannotBeBroughtBack(): void {
        $capabilities = $this->readOnlyCapabilities();
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('alice'));
        $plan = $this->moduleWith($capabilities)->preview('deck_delete_card', ['cardId' => 7], 'alice');
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
        $result = $this->registry($this->readOnlyCapabilities())->call('deck_move_card', $arguments, 'alice');
        self::assertNotTrue($result['isError'] ?? false);
        $structured = $result['structuredContent'] ?? $this->payload($result);
        self::assertTrue($structured['requiresConfirmation']);
        self::assertSame('deck_move_card', $structured['action']);
    }

    /** `confirm` is a boolean: an explicit null is refused by the schema, so it never reaches the write either. */
    public function testExplicitNullConfirmIsRefusedWithoutWriting(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry($this->readOnlyCapabilities())->call('deck_move_card', ['cardId' => 7, 'stackId' => 20, 'confirm' => null], 'alice');
    }

    public function testRegistryRunsTheWriteOnceConfirmed(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('alice'));
        $capabilities->ownership->method('stackOwnership')->willReturn($this->ownershipOf('alice'));
        $capabilities->cards->method('cardBoardId')->willReturn(1);
        $capabilities->writer->expects(self::once())->method('moveCard')->with('alice', 7, 20, null)->willReturn($this->card(['id' => 7, 'stackId' => 20]));
        $result = $this->registry($capabilities)->call('deck_move_card', ['cardId' => 7, 'stackId' => 20, 'confirm' => true], 'alice');
        self::assertNotTrue($result['isError'] ?? false);
        $structured = $result['structuredContent'] ?? $this->payload($result);
        self::assertArrayNotHasKey('requiresConfirmation', $structured);
    }

    private function registry(DeckDoubles $capabilities): ToolRegistry {
        $policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        foreach (GrantPolicy::CATALOG['deck'] as $operation) {
            $policy->setGrant('alice', 'deck', $operation, true);
        }
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $apps, $users);
        return new ToolRegistry([$this->moduleWith($capabilities, $users)], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->createMock(LoggerInterface::class)));
    }
}
