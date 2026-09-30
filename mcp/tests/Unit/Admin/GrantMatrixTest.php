<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\GrantPolicy;
use PHPUnit\Framework\TestCase;

final class GrantMatrixTest extends TestCase {
    private MatrixFixture $fx;

    protected function setUp(): void {
        $this->fx = new MatrixFixture($this);
        for ($i = 1; $i <= 120; $i++) {
            $this->fx->addUser(sprintf('u%03d', $i), sprintf('User %03d', $i), sprintf('user%03d@example.com', $i), true, $i % 3 === 0 ? ['sales'] : []);
        }
        $this->fx->addUser('ana', 'Ana Souza', 'ana.souza@corp.example', true, ['sales']);
        $this->fx->addUser('bruno', 'Bruno Lima', 'b.lima@corp.example', false);
    }

    public function testPagesOfFiftyWithOffsetHasMoreAndTotal(): void {
        $first = $this->fx->matrix()->page('', '', 1);
        $this->assertCount(GrantMatrix::PAGE_SIZE, $first['users']);
        $this->assertTrue($first['hasMore']);
        $this->assertSame(122, $first['total']);
        $this->assertSame(['', GrantMatrix::PAGE_SIZE + 1, 0], $this->fx->searches[0]);
        $last = $this->fx->matrix()->page('', '', 3);
        $this->assertCount(22, $last['users']);
        $this->assertFalse($last['hasMore']);
        $this->assertSame(['', GrantMatrix::PAGE_SIZE + 1, 100], $this->fx->searches[1]);
    }

    public function testSearchMatchesEmailButNeverReturnsIt(): void {
        $page = $this->fx->matrix()->page('corp.example', '', 1);
        $this->assertSame(['ana', 'bruno'], array_column($page['users'], 'uid'));
        $this->assertNull($page['total']);
        $this->assertFalse($page['hasMore']);
        $json = json_encode($page);
        $this->assertStringNotContainsString('@', $json);
        $this->assertStringNotContainsString('corp.example', $json);
        $this->assertSame(['uid', 'displayName', 'enabled', 'appsEnabled', 'eligible', 'connected', 'grants'], array_keys($page['users'][0]));
    }

    public function testGroupFilterUsesGroupMembersAndGroupSize(): void {
        $page = $this->fx->matrix()->page('', 'sales', 1);
        $this->assertSame(41, $page['total']);
        $this->assertCount(41, $page['users']);
        $this->assertContains('ana', array_column($page['users'], 'uid'));
        $filtered = $this->fx->matrix()->page('Ana', 'sales', 1);
        $this->assertSame(['ana'], array_column($filtered['users'], 'uid'));
        $this->assertNull($filtered['total']);
        $this->assertSame([['id' => 'sales', 'displayName' => 'Sales'], ['id' => 'admin', 'displayName' => 'Admin']], $page['groups']);
    }

    public function testDisabledUserIsFlagged(): void {
        $bruno = $this->fx->matrix()->page('bruno', '', 1)['users'][0];
        $this->assertFalse($bruno['enabled']);
        $this->assertTrue($this->fx->matrix()->page('ana', '', 1)['users'][0]['enabled']);
    }

    public function testStateIsLoadedInBatchWithPolicyDefaults(): void {
        $this->fx->policy->setEligible('ana', true);
        $this->fx->policy->setGrant('ana', 'notes', 'edit', true);
        $this->fx->config->batchCalls = 0;
        $page = $this->fx->matrix()->page('', '', 1);
        $this->assertSame(2 + array_sum(array_map('count', GrantPolicy::CATALOG)), $this->fx->config->batchCalls);
        $ana = array_column($page['users'], null, 'uid')['ana'];
        $this->assertTrue($ana['eligible']);
        $this->assertTrue($ana['grants']['notes']['edit']);
        $this->assertTrue($ana['grants']['files']['read']);
        $this->assertFalse($ana['grants']['files']['edit']);
        $this->assertFalse($ana['connected']);
    }

    public function testCatalogAppsAndService(): void {
        $page = $this->fx->matrix()->page('', '', 1);
        $this->assertSame(['files' => GrantPolicy::CATALOG['files'], 'notes' => GrantPolicy::CATALOG['notes'], 'calendar' => ['read']], $page['catalog']);
        $this->assertSame(['files' => true, 'notes' => true, 'deck' => false, 'calendar' => true, 'talk' => false], $page['appsEnabled']);
        $this->assertFalse($page['serviceEnabled']);
    }

    /**
     * The matrix renders whatever the catalog says, so every new files operation becomes a column with no
     * template change at all, and each starts denied like every operation but read.
     */
    public function testTheNewFilesRestoreColumnArrivesWithTheCatalog(): void {
        $page = $this->fx->matrix()->page('', '', 1);
        $this->assertSame(['read', 'edit', 'create', 'move', 'restore'], $page['catalog']['files']);
        $this->assertNotSame([], $page['users']);
        foreach ($page['users'] as $user) {
            foreach (['restore', 'create', 'move'] as $operation) {
                $this->assertArrayHasKey($operation, $user['grants']['files']);
                $this->assertFalse($user['grants']['files'][$operation], "$operation nasce desligado");
            }
        }
        $this->fx->policy->setGrant($page['users'][0]['uid'], 'files', 'restore', true);
        $this->assertTrue($this->fx->matrix()->page('', '', 1)['users'][0]['grants']['files']['restore']);
    }

    public function testDisabledCalendarWritesAreAbsentFromMatrixButSavedGrantsRemain(): void {
        $this->fx->policy->setGrant('bruno', 'calendar', 'create', true);

        $page = $this->fx->matrix()->page('Bruno', '', 1);

        $this->assertSame(['read'], $page['catalog']['calendar']);
        $this->assertTrue($page['users'][0]['grants']['calendar']['create']);
    }

    public function testMissingOptionalAppsAreOmittedWhileTheirGrantsRemainStored(): void {
        $this->fx->enabledApps = [];
        $this->fx->policy->setGrant('bruno', 'notes', 'edit', true);

        $page = $this->fx->matrix()->page('Bruno', '', 1);

        $this->assertSame(['files' => GrantPolicy::CATALOG['files']], $page['catalog']);
        $this->assertSame(['files' => true, 'notes' => false, 'deck' => false, 'calendar' => false, 'talk' => false], $page['appsEnabled']);
        $this->assertTrue($page['users'][0]['grants']['notes']['edit']);
    }

    public function testRestrictedAppIsUnavailableForUsersWithoutClearingTheirGrant(): void {
        $this->fx->appUsers['notes'] = ['ana'];
        $this->fx->policy->setGrant('bruno', 'notes', 'edit', true);

        $ana = $this->fx->matrix()->page('Ana', '', 1)['users'][0];
        $bruno = $this->fx->matrix()->page('Bruno', '', 1)['users'][0];

        $this->assertSame('ana', $ana['uid']);
        $this->assertTrue($ana['appsEnabled']['notes'], json_encode($ana['appsEnabled']));
        $this->assertFalse($bruno['appsEnabled']['notes']);
        $this->assertTrue($bruno['grants']['notes']['edit']);
    }

    public function testRejectsBadInput(): void {
        foreach ([['', '', 0], ['', '', GrantMatrix::MAX_PAGE + 1], [str_repeat('a', 101), '', 1], ['', 'ghosts', 1]] as [$search, $group, $page]) {
            try {
                $this->fx->matrix()->page($search, $group, $page);
                $this->fail("accepted $search/$group/$page");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
