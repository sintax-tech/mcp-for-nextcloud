<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Deck;


trait DeckToolFixtures {
    use DeckTestHelpers;
    protected function deckDoubles(): DeckDoubles {
        $d = new DeckDoubles();
        foreach ((new \ReflectionClass($d))->getProperties() as $p) {
            $p->setValue($d, $this->createMock($p->getType()->getName()));
        }
        // Assignment readback uses the real small predicate on mocked assignment data.
        $d->assignments->method('assignmentOf')->willReturnCallback(function (
            $uid,
            $id,
            $assignee,
        ) use ($d) {
            foreach ($d->assignments->cardAssignments($uid, $id) as $a) {
                if ((string) $a->getParticipant() === $assignee && $a->getType() === 0) {
                    return $a;
                }
            }
            return null;
        });
        $d->structureState->method('newBoard')->willReturnCallback(function (
            $uid,
            $title,
            $before,
        ) use ($d) {
            $found = null;
            foreach ($d->structureState->ownedBoards($uid) as $board) {
                if (!in_array((int) $board->getId(), $before, true) && (string) $board->getTitle() === $title && (int) $board->getDeletedAt() === 0 && ($found === null || (int) $board->getId() > (int) $found->getId())) {
                    $found = $board;
                }
            }
            return $found;
        });
        return $d;
    }

    protected function deckDoublesOwnedBy(string $owner, string $name = 'Pessoal'): DeckDoubles {
        $d = $this->deckDoubles();
        $d->ownership->method('cardOwnership')->willReturn($this->ownershipOf($owner, $name));
        $d->ownership->method('stackOwnership')->willReturn($this->ownershipOf($owner, $name));
        return $d;
    }

    protected function deckTool(
        string $class,
        DeckDoubles $d,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?\OCA\Mcp\Modules\Deck\Presentation\DeckFormatter $formatter = null,
        array $services = [],
    ): object {
        $services[\Psr\Log\LoggerInterface::class] = $logger ?? $this->createMock(\Psr\Log\LoggerInterface::class);
        foreach ((new \ReflectionClass($d))->getProperties() as $p) {
            $services[$p->getType()->getName()] = $p->getValue($d);
        }
        if ($formatter !== null) {
            $services[\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class] = $this->formatterFactory($formatter);
        }
        $build = function (string $type) use (&$build, &$services): object {
            if (isset($services[$type])) {
                return $services[$type];
            }
            if (interface_exists($type)) {
                return $services[$type] = $this->createMock($type);
            }
            $r = new \ReflectionClass($type);
            $args = [];
            foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
                $t = $p->getType();
                if ($t instanceof \ReflectionNamedType && $t->getName() === \OCA\Mcp\Platform\Time\UserTimezone::class && !isset($services[\OCP\IConfig::class])) {
                    $args[] = null;
                    continue;
                }
                if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) {
                    $args[] = $build($t->getName());
                } elseif ($p->isDefaultValueAvailable()) {
                    $args[] = $p->getDefaultValue();
                } else {
                    throw new \LogicException('Missing fixture dependency: ' . $type . '::$' . $p->getName());
                }
            }
            return $services[$type] = $r->newInstanceArgs($args);
        };
        return $build($class);
    }

    protected function deckModuleFixture(
        DeckDoubles $d,
        ?\OCP\App\IAppManager $apps = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?\OCP\IURLGenerator $urls = null,
        ?\OCP\AppFramework\Utility\ITimeFactory $time = null,
        ?\OCP\IUserManager $users = null,
        ?\OCP\IConfig $config = null,
    ): \OCA\Mcp\Modules\Deck\DeckModule {
        if ($apps === null) {
            $apps = $this->createMock(\OCP\App\IAppManager::class);
            $apps->method('isEnabledForUser')->willReturn(true);
        }
        $logger ??= $this->createMock(\Psr\Log\LoggerInterface::class);
        $services = [
            \OCP\IURLGenerator::class => $urls,
            \OCP\AppFramework\Utility\ITimeFactory::class => $time,
            \OCP\IUserManager::class => $users,
            \OCP\IConfig::class => $config,
        ];
        $formatters = new \OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory(
            $urls ?? $this->createMock(\OCP\IURLGenerator::class),
            $time ?? $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
            $users,
            $config === null ? null : new \OCA\Mcp\Platform\Time\UserTimezone($config),
        );
        $services[\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class] = $formatters;
        $factories = [];
        foreach (\OCA\Mcp\Modules\Deck\DeckToolCatalog::classes() as $class) {
            $factories[$class::definition()->name] = fn() => match ($class) {
                \OCA\Mcp\Modules\Deck\Tools\ListBoards::class => new \OCA\Mcp\Modules\Deck\Tools\ListBoards(
                    $d->boards->listBoards(...),
                    new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($logger),
                    $formatters,
                ),
                \OCA\Mcp\Modules\Deck\Tools\ListStacks::class => new \OCA\Mcp\Modules\Deck\Tools\ListStacks(
                    $d->stacks->listStacks(...),
                    new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($logger),
                    $formatters,
                ),
                \OCA\Mcp\Modules\Deck\Tools\ListCards::class => new \OCA\Mcp\Modules\Deck\Tools\ListCards($d->cards->listCards(...), new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($logger), $formatters),
                \OCA\Mcp\Modules\Deck\Tools\ReadCard::class => new \OCA\Mcp\Modules\Deck\Tools\ReadCard($d->cards->findCard(...), new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($logger), $formatters),
                \OCA\Mcp\Modules\Deck\Tools\FollowupCards::class => new \OCA\Mcp\Modules\Deck\Tools\FollowupCards(
                    $d->followup->followupCards(...),
                    new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($logger),
                    $formatters,
                ),
                default => $this->deckTool($class, $d, $logger, null, $services),
            };
        }
        return new \OCA\Mcp\Modules\Deck\DeckModule($factories, new \OCA\Mcp\Modules\Deck\Access\DeckAvailability($apps, $users));
    }
}
