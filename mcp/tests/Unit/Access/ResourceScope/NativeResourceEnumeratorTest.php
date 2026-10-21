<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator;
use PHPUnit\Framework\TestCase;

/** The native candidates of a target user (contracts B.6, F4): what Nextcloud gives the account, not the MCP view. */
final class NativeResourceEnumeratorTest extends TestCase {
    private ScopeFixture $fx;

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
    }

    public function testRootFoldersAreListedWithExcludedMarkedAndUnreadableOrFilesLeftOut(): void {
        $page = $this->fx->enumerator()->candidates('alice', 'files', '', 1);

        $this->assertSame(['Archive', 'Projects', 'Secret'], array_column($page['items'], 'name'));
        $this->assertSame(['/Archive', '/Projects', '/Secret'], array_column($page['items'], 'path'));
        $this->assertSame([false, false, true], array_column($page['items'], 'excluded'));
        $this->assertSame(['id' => (string)$this->fx->tree->rootFolder()->getId(), 'name' => '/', 'path' => '/', 'excluded' => false], $page['parent']);
        $this->assertSame([3, false], [$page['total'], $page['hasMore']]);
    }

    public function testPagesOfFiftyNeverTruncate(): void {
        for ($i = 1; $i <= 120; ++$i) {
            $this->fx->tree->addFolder(sprintf('/alice/files/Projects/F%03d', $i));
        }
        $seen = [];
        $enumerator = $this->fx->enumerator();
        for ($page = 1; ; ++$page) {
            $result = $enumerator->candidates('alice', 'files', '', $page, $this->fx->projects);
            $this->assertSame(120, $result['total']);
            $this->assertLessThanOrEqual(NativeResourceEnumerator::PAGE_SIZE, count($result['items']));
            $seen = [...$seen, ...array_column($result['items'], 'name')];
            if (!$result['hasMore']) {
                break;
            }
        }
        $this->assertSame(3, $page);
        $this->assertSame(array_map(static fn (int $i) => sprintf('F%03d', $i), range(1, 120)), $seen);
        $this->assertSame('/Projects/F001', $enumerator->candidates('alice', 'files', '', 1, $this->fx->projects)['items'][0]['path']);
    }

    public function testSearchIgnoresCaseAndCountsOnlyMatches(): void {
        $page = $this->fx->enumerator()->candidates('alice', 'files', 'ARCH', 1);
        $this->assertSame(['Archive'], array_column($page['items'], 'name'));
        $this->assertSame(1, $page['total']);
    }

    public function testCalendarAndTaskCandidatesFollowTheComponent(): void {
        $enumerator = $this->fx->enumerator();
        $this->assertSame(['11', '12'], array_column($enumerator->candidates('alice', 'calendar', '', 1)['items'], 'id'));
        $this->assertSame(['Team', 'Todo'], array_column($enumerator->candidates('alice', 'tasks', '', 1)['items'], 'name'));
        $this->assertSame([false, false], array_column($enumerator->candidates('alice', 'tasks', '', 1)['items'], 'excluded'));
    }

    /** F4: candidates come from Nextcloud alone, so an account without any MCP grant still lists its resources. */
    public function testCandidatesNeverConsultTheMcpPolicy(): void {
        $constructor = (new \ReflectionClass(NativeResourceEnumerator::class))->getConstructor();
        $types = array_map(static fn (\ReflectionParameter $p): string => (string)$p->getType(), $constructor->getParameters());
        $this->assertSame([\OCP\Files\IRootFolder::class, \OCA\Mcp\Platform\Dav\CalDav\CollectionAccess::class, \OCA\Mcp\Access\Visibility\VisibilityGuard::class], $types);
        $this->assertNotSame([], $this->fx->enumerator()->candidates('alice', 'calendar', '', 1)['items']);
    }

    public function testInvalidParentPageOrModuleIsRefused(): void {
        $enumerator = $this->fx->enumerator();
        foreach ([
            fn () => $enumerator->candidates('alice', 'files', '', 1, $this->fx->locked),
            fn () => $enumerator->candidates('alice', 'files', '', 1, $this->fx->file),
            fn () => $enumerator->candidates('alice', 'files', '', 1, '007'),
            fn () => $enumerator->candidates('alice', 'calendar', '', 1, $this->fx->projects),
            fn () => $enumerator->candidates('alice', 'files', '', 0),
            fn () => $enumerator->candidates('alice', 'files', str_repeat('a', 101), 1),
            fn () => $enumerator->candidates('alice', 'notes', '', 1),
        ] as $index => $call) {
            try {
                $call();
                $this->fail("Accepted case $index");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnUnreadableTreeIsUnavailableNeverEmpty(): void {
        $this->expectException(PolicyUnavailable::class);
        $this->fx->enumerator()->candidates('bob', 'files', '', 1);
    }

    public function testDescribeNamesSavedResourcesAndReportsTheGoneOnes(): void {
        $described = $this->fx->enumerator()->describe('alice', 'files', [$this->fx->projects, '987654', $this->fx->secret]);
        $this->assertSame([true, false, true], array_column($described, 'found'));
        $this->assertSame(['Projects', null, 'Secret'], array_column($described, 'name'));
        $this->assertSame([false, false, true], array_column($described, 'excluded'));
        $this->assertSame([['id' => '14', 'found' => false, 'name' => null, 'path' => null, 'excluded' => false]],
            $this->fx->enumerator()->describe('alice', 'calendar', ['14']));
    }

    /** Review F2: a tree that cannot be read is unavailability (retryable 503), never "these resources do not exist". */
    public function testInaccessibleIsUnavailableWhenTheTreeOrCalendarsCannotBeRead(): void {
        $this->assertSame([], $this->fx->enumerator()->inaccessible('alice', 'files', [$this->fx->projects, $this->fx->secret]));
        try {
            $this->fx->enumerator()->inaccessible('bob', 'files', ['5', '6']);
            $this->fail('Unreadable tree answered as inaccessible');
        } catch (PolicyUnavailable) {
        }
        $calendars = $this->createMock(\OCA\Mcp\Platform\Dav\CalDav\CollectionStore::class);
        $calendars->method('calendarsForPrincipal')->willThrowException(new \RuntimeException('CalDAV down'));
        $enumerator = new NativeResourceEnumerator($this->createMock(\OCP\Files\IRootFolder::class), new \OCA\Mcp\Platform\Dav\CalDav\CollectionAccess($calendars), $this->fx->guard);
        $this->expectException(PolicyUnavailable::class);
        $enumerator->inaccessible('alice', 'calendar', ['11']);
    }
}
