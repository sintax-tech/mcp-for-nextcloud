<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Deck;

/** Explicit test composition for native adapters; no production operation facade. */

trait DeckNativeFixtures {
    use DeckTestHelpers;
    protected function nativeDeck(
        \Psr\Container\ContainerInterface $container,
        \OCP\AppFramework\Utility\ITimeFactory $time,
        ?\OCA\Mcp\Platform\Time\UserTimezone $zones = null,
        ?\OCP\ISession $session = null,
    ): DeckDoubles {
        $session ??= $this->deckSession();
        $resolved = [];
        $provide = static function (string $class) use ($container, &$resolved): \Closure {
            return static function () use ($container, &$resolved, $class): object {
                return $resolved[$class] ??= $container->get($class);
            };
        };
        $caller = new \OCA\Mcp\Modules\Deck\Access\DeckCaller($provide(\OCA\Deck\Service\BoardService::class), $session);
        $instances = [\OCA\Mcp\Modules\Deck\Access\DeckCaller::class => $caller];
        $make = function (string $class) use (&$make, &$instances, $provide, $time, $zones): object {
            if (isset($instances[$class])) {
                return $instances[$class];
            }
            return $instances[$class] = match ($class) {
                \OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class => new \OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $provide(\OCA\Deck\Service\PermissionService::class),
                    $provide(\OCA\Deck\Db\StackMapper::class),
                    $provide(\OCP\IUserManager::class),
                    $provide(\OCA\Deck\Service\AssignmentService::class),
                    $provide(\OCA\Deck\Db\AssignmentMapper::class),
                    $provide(\OCA\Deck\Db\CardMapper::class),
                ),
                \OCA\Mcp\Modules\Deck\Reading\BoardReader::class => new \OCA\Mcp\Modules\Deck\Reading\BoardReader($make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class)),
                \OCA\Mcp\Modules\Deck\Reading\StackReader::class => new \OCA\Mcp\Modules\Deck\Reading\StackReader(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $provide(\OCA\Deck\Service\StackService::class),
                ),
                \OCA\Mcp\Modules\Deck\Reading\CardReader::class => new \OCA\Mcp\Modules\Deck\Reading\CardReader(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $make(\OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class),
                    $provide(\OCA\Deck\Service\PermissionService::class),
                    $provide(\OCA\Deck\Db\StackMapper::class),
                    $provide(\OCA\Deck\Db\CardMapper::class),
                    $provide(\OCA\Deck\Service\CardService::class),
                ),
                \OCA\Mcp\Modules\Deck\Followup\FollowupQuery::class => new \OCA\Mcp\Modules\Deck\Followup\FollowupQuery(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $make(\OCA\Mcp\Modules\Deck\Reading\BoardReader::class),
                    $make(\OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class),
                    $provide(\OCA\Deck\Db\StackMapper::class),
                    $provide(\OCA\Deck\Db\CardMapper::class),
                    $provide(\OCA\Deck\Service\PermissionService::class),
                    $time,
                    $zones,
                ),
                \OCA\Mcp\Modules\Deck\Cards\CardWriter::class => new \OCA\Mcp\Modules\Deck\Cards\CardWriter(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $provide(\OCA\Deck\Service\CardService::class),
                    $provide(\OCA\Deck\Service\PermissionService::class),
                    $provide(\OCA\Deck\Db\StackMapper::class),
                    $provide(\OCA\Deck\Db\CardMapper::class),
                    $zones,
                ),
                \OCA\Mcp\Modules\Deck\Cards\CardStateReader::class => new \OCA\Mcp\Modules\Deck\Cards\CardStateReader(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $provide(\OCA\Deck\Service\PermissionService::class),
                    $provide(\OCA\Deck\Db\CardMapper::class),
                    $provide(\OCA\Deck\Db\StackMapper::class),
                    $time,
                ),
                \OCA\Mcp\Modules\Deck\Access\OwnershipReader::class => new \OCA\Mcp\Modules\Deck\Access\OwnershipReader(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $provide(\OCA\Deck\Service\PermissionService::class),
                    $provide(\OCA\Deck\Db\StackMapper::class),
                    $provide(\OCA\Deck\Db\CardMapper::class),
                    $provide(\OCA\Deck\Service\BoardService::class),
                    $provide(\OCP\IUserManager::class),
                ),
                \OCA\Mcp\Modules\Deck\Structure\BoardCreator::class => new \OCA\Mcp\Modules\Deck\Structure\BoardCreator($make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class)),
                \OCA\Mcp\Modules\Deck\Structure\StackCreator::class => new \OCA\Mcp\Modules\Deck\Structure\StackCreator(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $provide(\OCA\Deck\Service\StackService::class),
                    $provide(\OCA\Deck\Service\PermissionService::class),
                    $provide(\OCA\Deck\Db\StackMapper::class),
                ),
                \OCA\Mcp\Modules\Deck\Structure\StructureStateReader::class => new \OCA\Mcp\Modules\Deck\Structure\StructureStateReader(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $provide(\OCA\Deck\Service\PermissionService::class),
                    $provide(\OCA\Deck\Db\StackMapper::class),
                    $provide(\OCA\Deck\Db\BoardMapper::class),
                ),
                \OCA\Mcp\Modules\Deck\Structure\StructureCardCounter::class => new \OCA\Mcp\Modules\Deck\Structure\StructureCardCounter(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $make(\OCA\Mcp\Modules\Deck\Access\OwnershipReader::class),
                    $provide(\OCA\Deck\Service\PermissionService::class),
                    $provide(\OCA\Deck\Db\StackMapper::class),
                    $provide(\OCA\Deck\Db\CardMapper::class),
                ),
                \OCA\Mcp\Modules\Deck\Structure\EmptyStructureDeletion::class => new \OCA\Mcp\Modules\Deck\Structure\EmptyStructureDeletion(
                    $make(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
                    $make(\OCA\Mcp\Modules\Deck\Structure\StructureCardCounter::class),
                    $make(\OCA\Mcp\Modules\Deck\Structure\StructureStateReader::class),
                    $provide(\OCA\Deck\Service\StackService::class),
                    $provide(\OCA\Deck\Service\BoardService::class),
                ),
                default => throw new \LogicException($class),
            };
        };
        $d = new DeckDoubles();
        $d->boards = $make(\OCA\Mcp\Modules\Deck\Reading\BoardReader::class);
        $d->stacks = $make(\OCA\Mcp\Modules\Deck\Reading\StackReader::class);
        $d->cards = $make(\OCA\Mcp\Modules\Deck\Reading\CardReader::class);
        $d->writer = $make(\OCA\Mcp\Modules\Deck\Cards\CardWriter::class);
        $d->state = $make(\OCA\Mcp\Modules\Deck\Cards\CardStateReader::class);
        $d->assignments = $make(\OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class);
        $d->ownership = $make(\OCA\Mcp\Modules\Deck\Access\OwnershipReader::class);
        $d->boardCreator = $make(\OCA\Mcp\Modules\Deck\Structure\BoardCreator::class);
        $d->stackCreator = $make(\OCA\Mcp\Modules\Deck\Structure\StackCreator::class);
        $d->structureState = $make(\OCA\Mcp\Modules\Deck\Structure\StructureStateReader::class);
        $d->counts = $make(\OCA\Mcp\Modules\Deck\Structure\StructureCardCounter::class);
        $d->deletion = $make(\OCA\Mcp\Modules\Deck\Structure\EmptyStructureDeletion::class);
        $d->followup = $make(\OCA\Mcp\Modules\Deck\Followup\FollowupQuery::class);
        return $d;
    }
}
