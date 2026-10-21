<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\ResourceScope\ScopeResolution;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;

/**
 * B4 on the real container and database: the resolution core reads the resource scope into each operation (S9),
 * a restriction narrows only resources, and an inconsistent row is unavailable, never unrestricted (S11). The scope
 * rows are written through the writer's transaction directly, since the activation barrier keeps the command closed.
 */
final class ScopeResolutionTest extends IntegrationTestCase {
    protected function tearDown(): void {
        $db = $this->official();
        foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
            $qb = $db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user(Fixtures::ALICE))))->executeStatement();
        }
        parent::tearDown();
    }

    /** @param list<string>|null $items */
    private function save(string $module, ?array $items): void {
        $scopes = Server::get(ResourceScopeMapper::class);
        Server::get(PolicyTransaction::class)->runInternal([], fn () => null, function ($snapshot, PolicyAttempt $attempt) use ($scopes, $module, $items): bool {
            $current = $attempt->state();
            $scopes->replace(Fixtures::ALICE, $module, $items, $current['revision']);
            return true;
        });
    }

    public function testTheNextOperationReadsTheScopeAndNothingIsRevoked(): void {
        $resolver = Server::get(PolicyResolver::class);
        $epoch = Server::get(PolicyStateMapper::class)->state()['auth_epoch'];

        $context = $resolver->begin(Fixtures::ALICE);
        try {
            $this->assertSame(ScopeResolution::UNRESTRICTED, $context->resolveEffective('files', 'read', ['42'])->scopeResolution->mode);
            $this->assertTrue($context->scope()->allows('files', ['42']));
        } finally { $context->close(); }

        $this->save('files', ['7']);
        $this->save('calendar', []);
        // The same resolver instance, as a long-lived process would reuse it.
        $context = $resolver->begin(Fixtures::ALICE);
        try {
            $files = $context->scope()->resolution('files');
            $this->assertSame([ScopeResolution::RESTRICTED, ['7'], 1], [$files->mode, $files->items, $files->count()]);
            $this->assertTrue($context->scope()->allows('files', ['42', '7']), 'an ancestor in the chain is enough');
            $this->assertFalse($context->scope()->allows('files', ['42']));
            $this->assertFalse($context->scope()->allows('calendar', ['1']));
            $this->assertTrue($context->scope()->allows('tasks', ['1']), 'tasks keeps its own scope');
            $this->assertTrue($context->scope()->allows('deck', ['1']), 'Deck has no scope');
        } finally { $context->close(); }
        $this->assertSame($epoch, Server::get(PolicyStateMapper::class)->state()['auth_epoch']);
    }

    public function testAnInconsistentRowMakesTheScopeModulesUnavailable(): void {
        $qb = $this->official()->getQueryBuilder();
        $qb->insert(ResourceScopeMapper::MODES_TABLE)
            ->setValue('user_key', $qb->createNamedParameter(IdentityKey::user(Fixtures::ALICE)))
            ->setValue('module', $qb->createNamedParameter('files'))
            ->setValue('user_id', $qb->createNamedParameter('mcp-it-collision'))
            ->setValue('policy_rev', $qb->createNamedParameter('1'))
            ->executeStatement();

        $context = Server::get(PolicyResolver::class)->begin(Fixtures::ALICE);
        try {
            foreach (ResourceScopeMapper::MODULES as $module) {
                $result = $context->resolveEffective($module, 'read', ['1']);
                $this->assertSame(ScopeResolution::UNAVAILABLE, $result->scopeResolution->mode, $module);
                $this->assertNotTrue($result->allowed, $module);
            }
            $this->assertSame(ScopeResolution::UNRESTRICTED, $context->scope()->resolution('deck')->mode);
            $this->expectException(PolicyUnavailable::class);
            $context->scope()->allows('files', ['1']);
        } finally { $context->close(); }
    }
}
