<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\ResourceScopeWriter;
use OCA\Mcp\Access\ResourceScope\ScopeInactive;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\ResourceScope\FilesReferenceScope;
use OCA\Mcp\Access\ResourceScope\FilesScope;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * B5 opens the activation barrier of files (F1 of the ticket review), end to end over the real writer, the resolution
 * core, the Files gate of the tools and checkout, and the Files scope step of the structured references: before the
 * opening a Files scope is refused and everything is reachable; with the shipped barrier a saved scope applies from the
 * next operation, never inside the one already open (S9), without moving any epoch or revoking a credential, and no
 * saved scope is ignored by any consumer.
 *
 * Folders of alice (ScopeFixture): Projects, Archive, Secret (excluded by tag), Locked (unreadable), and notes.txt.
 */
final class FilesScopeActivationTest extends TestCase {
    private ScopeFixture $fx;

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
        $this->fx->config->app['mcp']['service_enabled'] = '1';
        $this->fx->config->user['alice']['mcp']['eligible'] = '1';
        $this->fx->config->user['alice']['mcp']['connected'] = '1';
    }

    /** @return array{GrantPolicy, FilesAccessGuard, FilesReferenceScope} what alice's Files consumers read, through $writer's context */
    private function consumers(ResourceScopeWriter $writer): array {
        $policy = $this->fx->policy($this->fx->services());
        $scope = new FilesScope($policy, $this->fx->guard, $this->fx->tree->filesystemRoot());
        $tags = $this->createMock(ISystemTagObjectMapper::class);
        $tags->method('getTagIdsForObjects')->willReturnCallback(fn (array $ids): array => array_combine(array_map('strval', $ids),
            array_map(fn ($id): array => $this->fx->tags[(string)$id] ?? [], $ids)));
        $gate = new FilesAccessGuard($scope, $this->fx->config->mock($this), $tags, $this->fx->tree->filesystemRoot());
        return [$policy, $gate, new FilesReferenceScope($scope)];
    }

    /** @return array{tools:bool, reference:bool} whether a path of alice is reachable by the tools/checkout and by a structured reference, in one operation */
    private function reach(GrantPolicy $policy, FilesAccessGuard $gate, FilesReferenceScope $references, string $path): array {
        return $policy->within('alice', function () use ($gate, $references, $path): array {
            $gate->clearCache();
            $node = $this->fx->tree->node('/alice/files' . $path);
            return ['tools' => $gate->isVisible($node), 'reference' => $references->allows('alice', $node)];
        });
    }

    public function testBeforeTheOpeningAFilesScopeIsRefusedAndEverythingIsReachable(): void {
        $writer = $this->fx->commands(false);
        [$policy, $gate, $references] = $this->consumers($writer);
        $before = $this->fx->snapshot();
        try {
            $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
            $this->fail('Closed barrier accepted files');
        } catch (ScopeInactive) {
        }
        $this->assertSame($before, $this->fx->snapshot());
        $this->assertSame(['tools' => true, 'reference' => true], $this->reach($policy, $gate, $references, '/Archive'));
        $this->assertFalse($policy->within('alice', fn (): bool => $references->restricts('alice')));
    }

    public function testWithTheShippedBarrierASavedScopeAppliesFromTheNextOperationEverywhere(): void {
        $writer = $this->fx->commands(false, null, new ScopeActivation());
        [$policy, $gate, $references] = $this->consumers($writer);
        $epoch = $this->fx->snapshot()['auth_epoch'];
        $this->fx->oauth->insertToken(['owner_uid' => 'alice', 'client_id' => 'c', 'access_hash' => 'a', 'refresh_hash' => 'r'], $this->fx->now);
        $tokens = $this->fx->oauth->tokens;

        $policy->within('alice', function () use ($writer, $gate): void {
            $archive = $this->fx->tree->node('/alice/files/Archive');
            $this->assertTrue($gate->isVisible($archive));
            $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
            // S9: the operation already open keeps the scope it read.
            $gate->clearCache();
            $this->assertTrue($gate->isVisible($archive));
        });
        $this->assertSame(['tools' => false, 'reference' => false], $this->reach($policy, $gate, $references, '/Archive'));
        $this->assertSame(['tools' => true, 'reference' => true], $this->reach($policy, $gate, $references, '/Projects'));
        $this->assertSame(['tools' => false, 'reference' => false], $this->reach($policy, $gate, $references, '/notes.txt'));
        $this->assertTrue($policy->within('alice', fn (): bool => $references->restricts('alice')));
        // "/" lists the selected folder at its real path, never the root.
        $this->assertSame(['/alice/files/Projects'], $policy->within('alice', fn (): array => array_map(static fn ($folder): string => $folder->getPath(),
            $gate->scopeRoots($this->fx->tree->rootFolder()))));

        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', []);
        $this->assertSame(['tools' => false, 'reference' => false], $this->reach($policy, $gate, $references, '/Projects'), 'restricted to nothing');
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', null);
        $this->assertSame(['tools' => true, 'reference' => true], $this->reach($policy, $gate, $references, '/Archive'), 'unrestricted again');
        // The exclusion still wins with no scope at all.
        $this->assertSame(['tools' => false, 'reference' => true], $this->reach($policy, $gate, $references, '/Secret'));

        $this->assertSame($epoch, $this->fx->snapshot()['auth_epoch'], 'a scope moves no epoch');
        $this->assertSame($tokens, $this->fx->oauth->tokens, 'a scope revokes no credential');
        // A module whose barrier is closed is still refused: injected here, since every shipped barrier is open.
        $this->expectException(ScopeInactive::class);
        $this->fx->commands(false, null, new ScopeActivation(['files' => true, 'calendar' => false, 'tasks' => true]))
            ->setResourceScope($this->fx->revision(), 'root', 'alice', 'calendar', ['11']);
    }

    /** The gate outside an operation cannot know whose scope applies: unavailable, never unrestricted. */
    public function testTheGateOutsideAnOperationIsUnavailable(): void {
        [, $gate] = $this->consumers($this->fx->commands(false, null, new ScopeActivation()));
        $this->expectException(PolicyUnavailable::class);
        $gate->isVisible($this->fx->tree->node('/alice/files/Projects'));
    }

    /** S11 end to end: when the scope tables cannot be read, the Files gate is unavailable (503), never unrestricted. */
    public function testAnUnreadableScopeTableMakesTheGateUnavailable(): void {
        [$policy, $gate, $references] = $this->consumers($this->fx->commands(false, null, new ScopeActivation()));
        foreach ($this->fx->database->dbal->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%mcp_scope_%'") as $table) {
            $this->fx->database->dbal->executeStatement('DROP TABLE "' . $table . '"');
        }
        foreach (['items' => fn () => $policy->scopeItems('alice', 'files'), 'gate' => fn () => $gate->isVisible($this->fx->tree->node('/alice/files/Projects')),
            'references' => fn () => $references->restricts('alice')] as $label => $read) {
            try {
                $policy->within('alice', $read);
                $this->fail("$label answered");
            } catch (PolicyUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
