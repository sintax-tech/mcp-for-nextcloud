<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use PHPUnit\Framework\TestCase;

final class GrantPolicyTest extends TestCase {
    private InMemoryConfig $store;
    private GrantPolicy $policy;

    protected function setUp(): void {
        $this->store = new InMemoryConfig();
        $this->policy = new GrantPolicy($this->store->mock($this));
    }

    public function testEverythingStartsClosed(): void {
        $this->assertFalse($this->policy->globalEnabled());
        $this->assertFalse($this->policy->eligible('admin'));
        $this->assertFalse($this->policy->connected('admin'));
        $this->assertFalse($this->policy->canConnect('admin'));
    }

    public function testConnectionNeedsGlobalEligibilityAndPersonalActivation(): void {
        $this->policy->setGlobalEnabled(true);
        $this->assertFalse($this->policy->canConnect('alice'));
        $this->policy->setEligible('alice', true);
        $this->assertFalse($this->policy->canConnect('alice'));
        $this->policy->setConnected('alice', true);
        $this->assertTrue($this->policy->canConnect('alice'));
    }

    public function testEachSwitchRevokesOnNextCheck(): void {
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
        $this->policy->setConnected('alice', true);

        $this->policy->setConnected('alice', false);
        $this->assertFalse($this->policy->canConnect('alice'));
        $this->policy->setConnected('alice', true);

        $this->policy->setEligible('alice', false);
        $this->assertFalse($this->policy->canConnect('alice'));
        $this->policy->setEligible('alice', true);

        $this->policy->setGlobalEnabled(false);
        $this->assertFalse($this->policy->canConnect('alice'));
    }

    public function testReadStartsAllowedAndWriteStartsDenied(): void {
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $this->assertSame($operation === 'read', $this->policy->granted('alice', $module, $operation), "$module.$operation");
            }
        }
    }

    public function testCatalogMatchesContractAndFilesHasNoDelete(): void {
        $this->assertSame(['read', 'edit'], GrantPolicy::CATALOG['files']);
        $this->assertSame(['read', 'create', 'edit', 'move', 'delete', 'transfer'], GrantPolicy::CATALOG['calendar']);
        $this->assertSame(['read', 'reply', 'attach', 'quote'], GrantPolicy::CATALOG['talk']);
        $this->expectException(InvalidArgumentException::class);
        $this->policy->setGrant('alice', 'files', 'delete', true);
    }

    public function testGrantsAreIndependentPerUserAndOperation(): void {
        $this->policy->setGrant('alice', 'notes', 'edit', true);
        $this->policy->setGrant('alice', 'files', 'read', false);
        $this->assertTrue($this->policy->granted('alice', 'notes', 'edit'));
        $this->assertFalse($this->policy->granted('alice', 'notes', 'delete'));
        $this->assertFalse($this->policy->granted('alice', 'files', 'read'));
        $this->assertFalse($this->policy->granted('bob', 'notes', 'edit'));
        $this->assertTrue($this->policy->granted('bob', 'files', 'read'));
    }

    public function testStateSurvivesANewPolicyInstance(): void {
        $this->policy->setGlobalEnabled(true);
        $this->policy->setGrant('alice', 'deck', 'move', true);
        $fresh = new GrantPolicy($this->store->mock($this));
        $this->assertTrue($fresh->globalEnabled());
        $this->assertTrue($fresh->granted('alice', 'deck', 'move'));
    }

    public function testServiceSwitchUsesItsOwnKeyAndNeverCoreReservedKeys(): void {
        // Core stores the app's own enablement as 'yes' under mcp/enabled; it must not read as the MCP service.
        $this->store->app['mcp']['enabled'] = 'yes';
        $this->assertFalse($this->policy->globalEnabled());
        $this->policy->setGlobalEnabled(true);
        $this->assertSame(['enabled' => 'yes', 'service_enabled' => '1'], $this->store->app['mcp']);

        $this->policy->canConnect('alice');
        $this->policy->setEligible('alice', true);
        $this->policy->setConnected('alice', true);
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $this->policy->granted('alice', $module, $operation);
                $this->policy->setGrant('alice', $module, $operation, true);
            }
        }
        $this->policy->setGlobalEnabled(false);
        $keys = array_map(static fn (array $a) => $a[2], $this->store->accessed);
        $this->assertSame([], array_values(array_intersect($keys, GrantPolicy::RESERVED_APP_KEYS)));
        $this->assertSame(['mcp'], array_values(array_unique(array_map(static fn (array $a) => $a[1], $this->store->accessed))));
        $this->assertSame(['service_enabled'], array_values(array_unique(array_map(static fn (array $a) => $a[2],
            array_filter($this->store->accessed, static fn (array $a) => $a[0] === 'app')))));
    }

    public function testUnknownModuleIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->granted('alice', 'mail', 'read');
    }
}
