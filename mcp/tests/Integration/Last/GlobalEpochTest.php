<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Last;

use OCA\Mcp\Tests\Integration\OAuth\OAuthAccountTestCase;

use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCP\Server;

/**
 * The global auth epoch on the real server, in every cell. It runs in the last suite of phpunit.xml because its
 * cleanup removes every credential of the old epoch by design, which in the upgrade cells includes the baseline
 * credentials that OAuth/LegacyCredentialsTest checks first. There it also proves the epoch over rows that take their
 * (epoch, generation) from the column defaults the upgrade ALTER added.
 */
final class GlobalEpochTest extends OAuthAccountTestCase {
    public function testSwitchingTheServiceOffMovesTheGlobalEpochAndOnlyLaterCredentialsWork(): void {
        $second = $this->openIndependentConnection();
        $grant = $this->exchange($this->consent());
        // Written as 1.0.8 wrote it, without the new columns: (epoch, generation) come from the column defaults, which
        // in an upgrade cell are those of the ALTER that added them.
        $now = time();
        Server::get(OAuthStore::class)->insertToken(['user_id' => $this->uid, 'client_id' => self::CLIENT, 'resource' => self::RESOURCE,
            'scope' => 'mcp', 'access_hash' => Server::get(TokenHasher::class)->hash('ncmcp_at_' . $this->uid), 'access_expires' => $now + 60,
            'refresh_hash' => hash('sha256', 'legacy' . $this->uid), 'refresh_expires' => $now + 60, 'created_at' => $now], $now);
        $legacy = ['access_token' => 'ncmcp_at_' . $this->uid];
        $this->assertSame([['0', '1'], [$this->epoch, '2']], $this->stamps($second, 'mcp_oauth_tokens'));
        if ($this->epoch === '0') {
            $this->assertSame($this->uid, $this->bearer($legacy), 'a defaulted row is valid at epoch 0');
        }
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(false);
        $moved = DecimalCounter::increment($this->epoch);
        $this->assertSame($moved, (new PolicyStateMapper($second))->state()['auth_epoch']);
        $this->assertSame([], $this->stamps($second, 'mcp_oauth_tokens'));
        $policy->setGlobalEnabled(true);
        $this->assertNull($this->bearer($grant));
        $this->assertNull($this->bearer($legacy));
        $policy->setConnected($this->uid, true);
        $fresh = $this->exchange($this->consent());
        $this->assertSame([[$moved, '3']], $this->stamps($second, 'mcp_oauth_tokens'));
        $this->assertSame($this->uid, $this->bearer($fresh));
    }
}
