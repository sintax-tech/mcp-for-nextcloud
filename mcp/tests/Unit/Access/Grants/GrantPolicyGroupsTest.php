<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Grants;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Tests\Unit\ArrayGroupBackend;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GrantPolicy reads through the resolution of ticket 05 (T9, T10, T14): without any group policy the answers are the
 * 1.0.8 ones; a group may grant only from a trusted source with an active lifecycle; an answer that depends only on a
 * group of indeterminate policy is unknown, which fails closed (PolicyUnavailable) instead of becoming a default.
 */
final class GrantPolicyGroupsTest extends TestCase {
    private InMemoryConfig $data;
    private IConfig $config;
    private GrantPolicy $policy;
    /** @var array<string, list<string>> members by group id, served by one Database backend */
    private array $groups = [];
    /** @var array<string, list<string>> members by group id, served by a backend named LDAP */
    private array $ldap = [];

    protected function setUp(): void {
        $this->data = new InMemoryConfig();
        $this->config = $this->data->mock($this);
        $this->data->groupBackends = [new ArrayGroupBackend(fn (): array => $this->groups),
            new ArrayGroupBackend(fn (): array => $this->ldap, 'LDAP')];
        $this->policy = InMemoryConfig::policy($this->config, new InMemoryOAuthStore());
    }

    /** @param array<string, string|null> $values raw user values of alice by key; null leaves the key absent */
    private function alice(array $values, ?string $service): void {
        $this->data->user = ['alice' => ['mcp' => array_filter($values, static fn ($value): bool => $value !== null)]];
        $this->data->app = $service === null ? [] : ['mcp' => ['service_enabled' => $service]];
    }

    public static function legacyCases(): iterable {
        foreach ([null, '0', '1', 'yes'] as $eligible) {
            foreach ([null, '0', '1'] as $connected) {
                foreach ([null, '0', '1'] as $service) {
                    foreach ([null, '0', '1', ''] as $grant) {
                        yield [$eligible, $connected, $service, $grant];
                    }
                }
            }
        }
    }

    /** T9 without groups: every answer is the one of the 1.0.8 GrantPolicy, transcribed here as the oracle. */
    #[DataProvider('legacyCases')]
    public function testWithoutGroupPoliciesEveryAnswerIsThe108One(?string $eligible, ?string $connected, ?string $service, ?string $grant): void {
        $this->groups = ['team' => ['alice']];
        $values = ['eligible' => $eligible, 'connected' => $connected];
        foreach (['grant_notes_read', 'grant_notes_edit', 'grant_logs_read'] as $key) {
            $values[$key] = $grant;
        }
        $this->alice($values, $service);
        $legacyEligible = $eligible === '1';
        $legacyGranted = static fn (string $key, string $default): bool => ($grant ?? $default) === '1';
        $this->assertSame($legacyEligible, $this->policy->eligible('alice'));
        $this->assertSame($connected === '1', $this->policy->connected('alice'));
        $this->assertSame($service === '1' && $legacyEligible && $connected === '1', $this->policy->canConnect('alice'));
        $this->assertSame($legacyGranted('grant_notes_read', '1'), $this->policy->granted('alice', 'notes', 'read'));
        $this->assertSame($legacyGranted('grant_notes_edit', '0'), $this->policy->granted('alice', 'notes', 'edit'));
        $this->assertSame($legacyGranted('grant_logs_read', '0'), $this->policy->granted('alice', 'logs', 'read'));
        $state = $this->policy->forUsers(['alice'])['alice'];
        $this->assertSame($legacyEligible, $state['eligible']);
        $this->assertSame($legacyGranted('grant_notes_edit', '0'), $state['grants']['notes']['edit']);
    }

    public function testAnActiveDatabaseGroupGrantsEligibilityAndOperationsAndAnIndividualDenyWins(): void {
        $this->groups = ['team' => ['alice']];
        InMemoryConfig::groupPolicy($this->config, 'team', ['_system' => ['eligible'], 'notes' => ['edit']]);
        $this->alice(['connected' => '1'], '1');
        $this->assertTrue($this->policy->eligible('alice'));
        $this->assertTrue($this->policy->canConnect('alice'));
        $this->assertTrue($this->policy->granted('alice', 'notes', 'edit'));
        $this->assertFalse($this->policy->granted('alice', 'notes', 'delete'));
        $origins = $this->policy->forUsers(['alice'])['alice']['origins'];
        $this->assertSame(['source' => 'group', 'groupIds' => ['team']], $origins['eligible']);
        $this->assertSame(['source' => 'group', 'groupIds' => ['team']], $origins['grants']['notes']['edit']);

        $this->alice(['connected' => '1', 'eligible' => '0', 'grant_notes_edit' => '0'], '1');
        $this->assertFalse($this->policy->eligible('alice'));
        $this->assertFalse($this->policy->canConnect('alice'));
        $this->assertFalse($this->policy->granted('alice', 'notes', 'edit'));
    }

    /**
     * An LDAP group never grants: without a policy it changes nothing (eligibility stays individual); with one stored
     * (its source changed after a save) the policy is indeterminate, so what depended only on it is unknown, never
     * granted. An allowing default is not affected by it.
     */
    public function testAGroupFromAnUntrustedSourceNeverGrants(): void {
        $this->ldap = ['directory' => ['alice']];
        $this->alice(['connected' => '1'], '1');
        $this->assertFalse($this->policy->canConnect('alice'));
        $this->assertFalse($this->policy->granted('alice', 'notes', 'edit'));
        $this->alice(['connected' => '1', 'eligible' => '1'], '1');
        $this->assertTrue($this->policy->canConnect('alice'));

        InMemoryConfig::groupPolicy($this->config, 'directory', ['_system' => ['eligible'], 'notes' => ['edit', 'read']], 'needs_confirm');
        $this->assertTrue($this->policy->granted('alice', 'notes', 'read'));
        $this->assertTrue($this->policy->canConnect('alice'), 'the individual Allow does not depend on the group');
        $this->expectException(PolicyUnavailable::class);
        $this->policy->granted('alice', 'notes', 'edit');
    }

    public static function indeterminateStates(): iterable {
        yield ['needs_confirm'];
        yield ['pending_delete'];
    }

    /** Clarification 6: a sole indeterminate source is unknown (503, no cut); another valid source keeps the access. */
    #[DataProvider('indeterminateStates')]
    public function testASoleIndeterminateSourceIsUnknownAndAnotherValidSourceKeepsAccess(string $state): void {
        $this->groups = ['team' => ['alice'], 'staff' => ['alice']];
        InMemoryConfig::groupPolicy($this->config, 'team', ['_system' => ['eligible'], 'notes' => ['edit']], $state);
        $this->alice(['connected' => '1'], '1');
        foreach ([fn () => $this->policy->eligible('alice'), fn () => $this->policy->canConnect('alice'),
            fn () => $this->policy->granted('alice', 'notes', 'edit')] as $read) {
            try {
                $read();
                $this->fail('An unknown answer became a default');
            } catch (PolicyUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
        // Nothing to decide yet: switched off or disconnected is a known answer whatever the group says.
        $this->alice(['connected' => '0'], '1');
        $this->assertFalse($this->policy->canConnect('alice'));
        $state = $this->policy->forUsers(['alice'])['alice'];
        $this->assertNull($state['eligible']);
        $this->assertSame(['source' => 'unknown', 'groupIds' => ['team']], $state['origins']['eligible']);

        InMemoryConfig::groupPolicy($this->config, 'staff', ['_system' => ['eligible'], 'notes' => ['edit']]);
        $this->alice(['connected' => '1'], '1');
        $this->assertTrue($this->policy->canConnect('alice'));
        $this->assertTrue($this->policy->granted('alice', 'notes', 'edit'));
        InMemoryConfig::groupPolicy($this->config, 'staff', []);
        $this->alice(['connected' => '1', 'eligible' => '1'], '1');
        $this->assertTrue($this->policy->canConnect('alice'), 'an individual Allow is a valid source of its own');
    }

    /** T10: eligible and connected totals include group members, before any page; a Deny removes, a duplicate counts once. */
    public function testFiltersAndTotalsCountGroupEligibilityOnceAndHonourIndividualValues(): void {
        $this->groups = ['team' => ['ana', 'bruno', 'carla', '10'], 'staff' => ['ana', 'dora'], 'paused' => ['eva']];
        $this->ldap = ['directory' => ['fabio']];
        InMemoryConfig::groupPolicy($this->config, 'team', ['_system' => ['eligible']]);
        InMemoryConfig::groupPolicy($this->config, 'staff', ['_system' => ['eligible']]);
        InMemoryConfig::groupPolicy($this->config, 'paused', ['_system' => ['eligible']], 'needs_confirm');
        // A source lost after its save: already needs_confirm, so this read persists nothing.
        InMemoryConfig::groupPolicy($this->config, 'directory', ['_system' => ['eligible']], 'needs_confirm');
        $this->data->user = [
            'bruno' => ['mcp' => ['eligible' => '0', 'connected' => '1']],
            'carla' => ['mcp' => ['eligible' => 'garbled']],
            'dora' => ['mcp' => ['connected' => '1']],
            'gil' => ['mcp' => ['eligible' => '1']],
            'hugo' => ['mcp' => ['connected' => '1']],
            '10' => ['mcp' => ['connected' => '1']],
        ];
        $this->assertSame(['10', 'ana', 'dora', 'gil'], $this->policy->flaggedUsers(GrantPolicy::ELIGIBLE_KEY));
        // Connected needs eligibility: bruno (Deny) and hugo (no eligibility) switched it on without counting.
        $this->assertSame(['10', 'dora'], $this->policy->connectedUsers());
    }

    /** T14: one instance across operations sees each change; inside one operation the snapshot is reused, then refreshed. */
    public function testOneInstanceReusesASnapshotPerOperationOnly(): void {
        $this->groups = ['team' => ['alice']];
        $this->alice(['connected' => '1'], '1');
        InMemoryConfig::groupPolicy($this->config, 'team', ['notes' => ['edit']]);
        $this->assertTrue($this->policy->granted('alice', 'notes', 'edit'));
        InMemoryConfig::groupPolicy($this->config, 'team', []);
        $this->assertFalse($this->policy->granted('alice', 'notes', 'edit'), 'a new operation reads the new policy');

        InMemoryConfig::groupPolicy($this->config, 'team', ['notes' => ['edit']]);
        $reads = $this->policy->within('alice', function (): array {
            $first = $this->policy->granted('alice', 'notes', 'edit');
            InMemoryConfig::groupPolicy($this->config, 'team', []);
            $reused = $this->policy->granted('alice', 'notes', 'edit');
            // A write through the service discards the snapshot of the open operation.
            $this->policy->setGrant('alice', 'notes', 'delete', true);
            return [$first, $reused, $this->policy->granted('alice', 'notes', 'edit'), $this->policy->granted('alice', 'notes', 'delete')];
        });
        $this->assertSame([true, true, false, true], $reads);
        $this->assertFalse($this->policy->granted('alice', 'notes', 'edit'));
    }

    public function testAnAbandonedWriterContextFailsEveryReadClosed(): void {
        $this->alice(['eligible' => '1', 'connected' => '1'], '1');
        $writer = InMemoryConfig::services($this->config)->transaction;
        (new \ReflectionProperty($writer, 'usable'))->setValue($writer, false);
        foreach ([fn () => $this->policy->globalEnabled(), fn () => $this->policy->connected('alice'),
            fn () => $this->policy->canConnect('alice'), fn () => $this->policy->granted('alice', 'files', 'read'),
            fn () => $this->policy->flaggedUsers(GrantPolicy::CONNECTED_KEY)] as $read) {
            try {
                $read();
                $this->fail('An unusable state was read as a default');
            } catch (PolicyUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** The decision a consent or an issuance acts on carries the gens of the groups that admitted the user (F3). */
    public function testAdmissionNamesTheGrantingLifecyclesAndRefusesWithoutDeciding(): void {
        $this->groups = ['team' => ['alice'], 'staff' => ['alice']];
        $gen = InMemoryConfig::groupPolicy($this->config, 'team', ['_system' => ['eligible']]);
        InMemoryConfig::groupPolicy($this->config, 'staff', ['notes' => ['edit']]);
        $this->alice([], '1');
        $this->assertSame([['groupId' => 'team', 'gen' => $gen]], $this->policy->admission('alice', false));
        $this->assertNull($this->policy->admission('alice', true), 'an issuance also needs the connection');
        $this->alice(['eligible' => '1', 'connected' => '1'], '1');
        $this->assertSame([], $this->policy->admission('alice', true), 'an individual Allow names no group');
        $this->alice([], '0');
        $this->assertNull($this->policy->admission('alice', false));
    }

    /** grantedAny is three-valued: a known Allow wins in any order; unknown without Allow throws; all denied is false. */
    public function testGrantedAnyIsThreeValuedAndOrderFree(): void {
        $this->groups = ['paused' => ['alice']];
        InMemoryConfig::groupPolicy($this->config, 'paused', ['files' => ['share']], 'needs_confirm');
        $this->alice(['eligible' => '1', 'connected' => '1', 'grant_files_link' => '1'], '1');
        $this->assertTrue($this->policy->grantedAny('alice', 'files', ['share', 'link']));
        $this->assertTrue($this->policy->grantedAny('alice', 'files', ['link', 'share']));
        $this->alice(['eligible' => '1', 'connected' => '1', 'grant_files_link' => '0'], '1');
        foreach ([['share', 'link'], ['link', 'share'], ['share']] as $operations) {
            try {
                $this->policy->grantedAny('alice', 'files', $operations);
                $this->fail('An unknown alternative was answered as a denial');
            } catch (PolicyUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
        $this->alice(['eligible' => '1', 'connected' => '1', 'grant_files_link' => '0', 'grant_files_share' => '0'], '1');
        $this->assertFalse($this->policy->grantedAny('alice', 'files', ['share', 'link']));
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->grantedAny('alice', 'files', ['share', 'delete']);
    }
}
