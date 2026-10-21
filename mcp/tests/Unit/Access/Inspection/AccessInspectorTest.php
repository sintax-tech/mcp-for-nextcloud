<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Inspection;

use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Inspection\AccessInspector as I;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCA\Mcp\Access\Inspection\CalendarAccessExplainer;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The inspector's answers are the decisions of the tools (ticket 13: T9 inspection equals the real tool, T11 the next
 * operation; issue #4 A5/S13 for a path): the effective access of every operation with its first gate and its resolved
 * grant, and for one path the gate, scope or exclusion that keeps the Files tools out, "allowed" only with every gate
 * evaluated.
 *
 * alice's tree (ScopeFixture): /Projects, /Archive, /Secret (tagged excluded), /Locked (unreadable), /notes.txt.
 */
final class AccessInspectorTest extends TestCase {
    private ScopeFixture $fx;
    private PolicyServices $services;
    private I $inspector;

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
        $this->fx->config->app['mcp']['service_enabled'] = '1';
        $this->fx->config->user['alice']['mcp']['eligible'] = '1';
        $this->fx->config->user['alice']['mcp']['connected'] = '1';
        $this->fx->tree->addFile('/alice/files/Secret/plan.md', 'x');
        $this->services = $this->fx->services();
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $this->fx->tree->rootFolder() : throw new NotFoundException());
        $resolver = $this->fx->resolver($this->services);
        $this->inspector = new I($resolver, $root, $this->fx->guard,
            new CalendarAccessExplainer($resolver, new CalendarAccess($this->fx->calendars)),
            new \OCA\Mcp\Access\ResourceScope\FilesScope($this->fx->policy($this->services), $this->fx->guard, $this->fx->tree->filesystemRoot()));
    }

    /** @return array<string, array> by "module.operation" */
    private function effective(string $uid = 'alice'): array {
        $out = [];
        foreach ($this->inspector->effective($uid)['operations'] as $row) {
            $out[$row['module'] . '.' . $row['operation']] = $row;
        }
        return $out;
    }

    public function testT9EveryCatalogOperationHasItsFirstGateAndItsResolvedGrant(): void {
        $this->fx->config->user['alice']['mcp']['grant_notes_edit'] = '1';
        $this->fx->config->user['alice']['mcp']['grant_files_read'] = '0';
        $rows = $this->effective();

        $expected = 0;
        foreach (GrantPolicy::CATALOG as $operations) {
            $expected += count($operations);
        }
        $this->assertCount($expected, $rows);
        $this->assertSame([true, 'allowed', null, 'default'], self::summary($rows['notes.read']));
        $this->assertSame([true, 'allowed', null, 'individual_allow'], self::summary($rows['notes.edit']));
        $this->assertSame([false, 'grant', 'grant', 'default'], self::summary($rows['notes.delete']));
        $this->assertSame([false, 'grant', 'grant', 'individual_deny'], self::summary($rows['files.read']));
        $this->assertSame(['mode' => 'unrestricted', 'count' => null], $rows['notes.read']['scope']);
    }

    public function testT9AGrantStaysReadableWhileATemporaryGateBlocks(): void {
        $this->fx->config->user['alice']['mcp']['grant_notes_edit'] = '1';
        $this->fx->config->app['mcp']['service_enabled'] = '0';
        $rows = $this->effective();
        $this->assertSame([false, 'service', 'service', 'individual_allow'], self::summary($rows['notes.edit']));
        $this->assertTrue($rows['notes.edit']['grant']['allowed']);

        $this->fx->config->app['mcp']['service_enabled'] = '1';
        unset($this->fx->config->user['alice']['mcp']['connected']);
        $this->assertSame([false, 'connection', 'connection', 'individual_allow'], self::summary($this->effective()['notes.edit']));
    }

    /** T9: what the inspector allows is exactly what the tools' admission (connection + grant) allows. */
    public function testT9TheInspectorAgreesWithTheToolsAdmission(): void {
        $policy = $this->fx->policy($this->services);
        foreach ([[], ['grant_deck_edit' => '1'], ['grant_files_read' => '0'], ['connected' => null]] as $values) {
            foreach ($values as $key => $value) {
                if ($value === null) {
                    unset($this->fx->config->user['alice']['mcp'][$key]);
                } else {
                    $this->fx->config->user['alice']['mcp'][$key] = $value;
                }
            }
            foreach ($this->effective() as $key => $row) {
                [$module, $operation] = explode('.', $key);
                $tool = $policy->canConnect('alice') && $policy->granted('alice', $module, $operation);
                $this->assertSame($tool, $row['allowed'], $key . ' ' . json_encode($values));
            }
        }
    }

    public function testAnAccountThatDoesNotExistIsBlockedByTheAccountGate(): void {
        foreach ($this->effective('nobody') as $key => $row) {
            $this->assertSame([false, 'account'], [$row['allowed'], $row['blockingGate']], $key);
        }
    }

    /** T11: a change applies to the next question, which reads the policy again. */
    public function testT11TheNextQuestionReadsThePolicyAgain(): void {
        $this->assertFalse($this->effective()['notes.edit']['allowed']);
        $this->fx->config->user['alice']['mcp']['grant_notes_edit'] = '1';
        $this->assertTrue($this->effective()['notes.edit']['allowed']);
    }

    /** @return array<string, array{\Closure(self): void, string, string, array{reason:string, gate:?string, exclusion:?string, folder:?string}}> */
    public static function paths(): array {
        $none = static function (): void {};
        $answer = static fn (string $reason, ?string $gate, ?string $exclusion = null, ?string $folder = null): array
            => ['reason' => $reason, 'gate' => $gate, 'exclusion' => $exclusion, 'folder' => $folder];
        return [
            'readable folder' => [$none, 'alice', '/Projects', $answer(I::ALLOWED, null)],
            'file at the top' => [$none, 'alice', '/notes.txt', $answer(I::ALLOWED, null)],
            'direct tag' => [$none, 'alice', '/Secret', $answer(I::EXCLUDED, 'exclusion', VisibilityGuard::EXPLAINED_TAG_DIRECT)],
            'inherited from a folder' => [$none, 'alice', '/Secret/plan.md', $answer(I::EXCLUDED, 'exclusion', VisibilityGuard::EXPLAINED_TAG_FOLDER, '/Secret')],
            'invalid configuration' => [static fn (self $t) => $t->fx->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '{}', 'alice', '/Projects',
                $answer(I::EXCLUDED, 'exclusion', VisibilityGuard::EXPLAINED_INVALID_CONFIGURATION)],
            'no native access' => [$none, 'alice', '/Locked', $answer(I::NO_NATIVE_ACCESS, 'native_acl')],
            'missing path' => [$none, 'alice', '/Nowhere', $answer(I::NOT_FOUND, 'native_acl')],
            'outside the Files scope' => [static fn (self $t) => $t->fx->commands()->setResourceScope($t->fx->revision(), 'root', 'alice', 'files', [$t->fx->archive]),
                'alice', '/Projects', $answer(I::OUT_OF_SCOPE, 'resource_scope')],
            'grant first' => [static fn (self $t) => $t->fx->config->user['alice']['mcp']['grant_files_read'] = '0', 'alice', '/Secret', $answer(I::BLOCKED, 'grant')],
            'service off' => [static fn (self $t) => $t->fx->config->app['mcp']['service_enabled'] = '0', 'alice', '/Projects', $answer(I::BLOCKED, 'service')],
            'unreadable scope' => [static fn (self $t) => $t->fx->database->dbal->executeStatement('DROP TABLE ' . ResourceScopeMapper::ITEMS_TABLE),
                'alice', '/Projects', $answer(I::NOT_EVALUATED, 'resource_scope_unknown')],
            'unreadable policy' => [static fn (self $t) => $t->fx->database->dbal->executeStatement('DELETE FROM mcp_policy_state'),
                'alice', '/Projects', $answer(I::NOT_EVALUATED, 'policy')],
            'account that does not exist' => [$none, 'nobody', '/Projects', $answer(I::BLOCKED, 'account')],
        ];
    }

    #[DataProvider('paths')]
    public function testS13APathIsExplainedByTheDecisionOfTheTools(\Closure $arrange, string $uid, string $path, array $expected): void {
        $arrange($this);
        $this->assertSame($expected, $this->inspector->path($uid, $path));
        // The Files tools reach the path only when the inspector says allowed (visibility guard + native read).
        if (in_array($expected['reason'], [I::ALLOWED, I::EXCLUDED], true)) {
            $node = $this->fx->tree->node('/alice/files' . $path);
            $this->assertSame($expected['reason'] === I::ALLOWED, $this->fx->guard->isVisible($node) && $node->isReadable());
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidPaths(): array {
        return ['parent segment' => ['/Projects/../Secret'], 'NUL' => ["/Projects\0"], 'control character' => ["/Pro\x01jects"]];
    }

    #[DataProvider('invalidPaths')]
    public function testAPathOutsideTheContractIsRefused(string $path): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->inspector->path('alice', $path);
    }

    /** B1 of the 13/A5 review: the path is read as the tools read it (PathGuard::normalize, '/' is the home). */
    public function testAPathIsNormalizedLikeTheTools(): void {
        $allowed = ['reason' => I::ALLOWED, 'gate' => null, 'exclusion' => null, 'folder' => null];
        foreach (['Projects', '/./Projects', '//Projects/', '\\Projects', '/Projects/.'] as $path) {
            $this->assertSame($allowed, $this->inspector->path('alice', $path), $path);
        }
        foreach (['', '/'] as $home) {
            $this->assertSame($allowed, $this->inspector->path('alice', $home), 'the home: ' . json_encode($home));
        }
    }

    /** B2 of the 13/A5 review: the exclusion is checked before the native read, as NodeAccess::get does. */
    public function testAnExcludedItemTheUserCannotReadIsExcludedFirst(): void {
        $id = (string)$this->fx->tree->addFolder('/alice/files/Closed', ['readable' => false]);
        $this->fx->tags[$id] = [ScopeFixture::HIDDEN_TAG];
        $this->assertSame(['reason' => I::EXCLUDED, 'gate' => 'exclusion', 'exclusion' => VisibilityGuard::EXPLAINED_TAG_DIRECT, 'folder' => null],
            $this->inspector->path('alice', '/Closed'));
    }

    public function testACalendarIsExplainedByTheCalendarExplainer(): void {
        $this->fx->commands()->setResourceScope($this->fx->revision(), 'root', 'alice', 'calendar', ['11']);
        $this->assertSame(['reason' => I::OUT_OF_SCOPE, 'gate' => 'resource_scope'], $this->inspector->calendar('alice', 'calendar', '12', 'read'));
        $this->assertSame(['reason' => I::ALLOWED, 'gate' => null], $this->inspector->calendar('alice', 'calendar', '11', 'read'));
    }

    /** @return array{?bool, string, ?string, string} allowed, reason, gate and grant source */
    private static function summary(array $row): array {
        return [$row['allowed'], $row['reasonCode'], $row['blockingGate'], $row['grant']['source']];
    }

    /**
     * B1 of the merge review: candidates that cannot be built leave the scope gate not evaluated under the one name the
     * admin page knows for it, resource_scope_unknown, the same as an unreadable scope.
     */
    public function testUnbuildableCandidatesNameTheUnknownScopeGate(): void {
        $this->fx->commands()->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
        $scope = $this->createMock(\OCA\Mcp\Access\ResourceScope\FilesScope::class);
        $scope->method('candidatesIn')->willThrowException(new \RuntimeException('Unresolved chain'));
        $resolver = $this->fx->resolver($this->services);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $this->fx->tree->rootFolder());
        $inspector = new I($resolver, $root, $this->fx->guard, new CalendarAccessExplainer($resolver, new CalendarAccess($this->fx->calendars)), $scope);
        $this->assertSame(['reason' => I::NOT_EVALUATED, 'gate' => 'resource_scope_unknown', 'exclusion' => null, 'folder' => null],
            $inspector->path('alice', '/Projects'));
    }
}
