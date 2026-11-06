<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\OAuth\Authorization\OAuthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Security contract of withdrawing and reintroducing a CIMD host at every credential boundary. */
final class CimdRevocationTest extends TestCase {
	private CimdRevocationRig $rig;

	/** @return void Production services with independent local host state. */
	protected function setUp(): void { $this->rig = new CimdRevocationRig($this); }

	/** @param callable $action Refused operation. @return void A protocol error, never an issued credential. */
	private function refused(callable $action): void {
		try { $action(); self::fail('Withdrawn host was admitted'); }
		catch (OAuthException $e) { self::assertContains($e->error, ['invalid_client', 'invalid_grant']); }
	}

	/** @return void Pending pages, including deny, cannot return to a withdrawn callback or issue a code. */
	public function testPendingConsentIsCutAndCannotReturnAfterReinsertion(): void {
		$pending = $this->rig->request();
		$this->rig->hosts(['chatgpt.com']);
		$this->refused(fn () => $this->rig->validator->revalidate($pending));
		$this->refused(fn () => $this->rig->tokens->consent($pending, 'alice'));
		$this->rig->hosts(['chatgpt.com', 'claude.ai']);
		$this->refused(fn () => $this->rig->validator->revalidate($pending));
		$this->refused(fn () => $this->rig->tokens->consent($pending, 'alice'));
		self::assertNotNull($this->rig->tokens->consent($this->rig->request(), 'alice'));
	}

	/** @return void All live credentials of the exact host are removed; the same user's other host remains usable. */
	public function testCodeRefreshAndBearerAreCutWithoutTouchingOtherHosts(): void {
		$tokens = $this->rig->issue();
		$other = $this->rig->issue(CimdRevocationRig::KEEP);
		$code = $this->rig->tokens->consent($this->rig->request(), 'alice');
		$this->rig->hosts(['chatgpt.com']);
		$this->refused(fn () => $this->rig->exchange($code));
		$this->refused(fn () => $this->rig->tokens->refresh(['client_id' => CimdRevocationRig::CLIENT, 'refresh_token' => $tokens['refresh_token']]));
		self::assertNull($this->rig->bearer->authenticate('Bearer ' . $tokens['access_token'], CimdRevocationRig::RESOURCE));
		self::assertNotNull($this->rig->bearer->authenticate('Bearer ' . $other['access_token'], CimdRevocationRig::RESOURCE));
		$this->rig->hosts(['chatgpt.com', 'claude.ai']);
		self::assertNull($this->rig->bearer->authenticate('Bearer ' . $tokens['access_token'], CimdRevocationRig::RESOURCE));
		$this->refused(fn () => $this->rig->exchange($code));
		$this->refused(fn () => $this->rig->tokens->refresh(['client_id' => CimdRevocationRig::CLIENT, 'refresh_token' => $tokens['refresh_token']]));
		self::assertNotEmpty($this->rig->issue());
	}

	/** @return void Generation is authoritative even if stale credential rows survived an out-of-band operation. */
	public function testSurvivingRowsNeverReviveInTheNewHostGeneration(): void {
		$tokens = $this->rig->issue();
		$code = $this->rig->tokens->consent($this->rig->request(), 'alice');
		[$savedTokens, $savedCodes] = [$this->rig->store->tokens, $this->rig->store->codes];
		$this->rig->hosts(['chatgpt.com']);
		$this->rig->hosts(['claude.ai', 'chatgpt.com']);
		$this->rig->store->tokens = $savedTokens;
		$this->rig->store->codes = $savedCodes;
		self::assertNull($this->rig->bearer->authenticate('Bearer ' . $tokens['access_token'], CimdRevocationRig::RESOURCE));
		$this->refused(fn () => $this->rig->tokens->refresh(['client_id' => CimdRevocationRig::CLIENT, 'refresh_token' => $tokens['refresh_token']]));
		$this->refused(fn () => $this->rig->exchange($code));
	}

	/** @return array<string,array{string}> Every issuing boundary under the policy mutex. */
	public static function units(): array { return ['exchange' => ['exchange'], 'refresh' => ['refresh'], 'consent' => ['consent']]; }

	/** @param string $unit Boundary that read old state before withdrawal. @return void Nothing is issued after the cut. */
	#[DataProvider('units')]
	public function testWithdrawalBetweenExternalPhaseAndMutexWins(string $unit): void {
		$tokens = $this->rig->issue();
		$request = $this->rig->request();
		$code = $this->rig->tokens->consent($request, 'alice');
		$this->rig->between = function (): void {
			$this->rig->hosts(['chatgpt.com']);
			$this->rig->hosts(['claude.ai', 'chatgpt.com']);
		};
		$this->refused(fn () => match ($unit) {
			'exchange' => $this->rig->exchange($code),
			'refresh' => $this->rig->tokens->refresh(['client_id' => CimdRevocationRig::CLIENT, 'refresh_token' => $tokens['refresh_token']]),
			'consent' => $this->rig->tokens->consent($request, 'alice'),
		});
		self::assertSame([], $this->rig->store->tokens);
		self::assertSame([], $this->rig->store->codes);
	}

	/** @return void Upgrade credentials from a host already absent from trust cannot revive on its first new admission. */
	public function testAddingAnUnimportedHostNeverAdmitsLegacyGenerationOne(): void {
		$tokens = $this->rig->issue();
		foreach ($this->rig->store->tokens as &$row) { $row['client_id'] = 'https://legacy.example/client'; }
		unset($row);
		$this->rig->hosts(['claude.ai', 'chatgpt.com', 'legacy.example']);
		self::assertSame('2', $this->rig->table->mapper->cimdHosts()->find('legacy.example')['gen']);
		self::assertNull($this->rig->bearer->authenticate('Bearer ' . $tokens['access_token'], CimdRevocationRig::RESOURCE));
		$this->refused(fn () => $this->rig->tokens->refresh(['client_id' => 'https://legacy.example/client', 'refresh_token' => $tokens['refresh_token']]));
	}

	/** @return void A stale config cannot override local tombstones and a failed database read fails closed. */
	public function testBearerDoesNotTrustStaleAppConfigAndFailsClosedOnStorageFailure(): void {
		$tokens = $this->rig->issue();
		$this->rig->table->mapper->cimdHosts()->put('claude.ai', false, '2');
		$this->rig->data->app['mcp']['oauth_client_hosts'] = 'claude.ai,chatgpt.com';
		self::assertNull($this->rig->bearer->authenticate('Bearer ' . $tokens['access_token'], CimdRevocationRig::RESOURCE));
		$this->rig->table->db->dbal->executeStatement('DROP TABLE mcp_cimd_hosts');
		$this->expectException(PolicyUnavailable::class);
		$this->rig->bearer->authenticate('Bearer ' . $tokens['access_token'], CimdRevocationRig::RESOURCE);
	}
}
