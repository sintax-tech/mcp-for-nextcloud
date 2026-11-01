<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Guests;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Server;
use RuntimeException;

/**
 * Shared lifecycle and transport support for disposable Guests accounts.
 */
trait GuestTestSupport {
	/**
	 * Bytes of entropy for the disposable guest password.
	 */
	private const PASSWORD_RANDOM_BYTES = 24;
	/**
	 * Bytes of entropy for the resource suffix in the parity scenario.
	 */
	private const PARITY_SUFFIX_BYTES = 6;
	/**
	 * Bytes of entropy for the short resource suffixes in the restrictions scenarios.
	 */
	private const SHORT_SUFFIX_BYTES = 3;
	/**
	 * Maximum characters of a refused tool reply retained in test observations.
	 */
	private const MAX_OBSERVATION_CHARS = 300;
	/**
	 * Disposable guest quota sufficient for the upload controls.
	 */
	private const GUEST_QUOTA = '10 MB';

	private static bool $enabledHere = false;
	/**
	 * Credentials belonging to this class's disposable guest.
	 *
	 * @var array<string, Credential> Credentials of this class's disposable guest.
	 */
	private static array $logins = [];
	/**
	 * Share identifiers retained until the consuming class cleans its scenario.
	 *
	 * @var list<string> Scenario-owned share identifiers, interpreted by the class cleanup hook.
	 */
	private array $shares = [];
	private ?int $mountId = null;

	/**
	 * Prepare a separate disposable guest and the grants needed by the positive controls.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$apps = Server::get(IAppManager::class);
		if (!$apps->isEnabledForAnyone('guests')) {
			self::occ('app:enable', 'guests');
			self::$enabledHere = true;
		}
		// Created before this process looks the guest up: the backend remembers an account it did not find.
		putenv('OC_PASS=' . bin2hex(random_bytes(self::PASSWORD_RANDOM_BYTES)));
		try {
			self::occ('guests:add', Fixtures::ALICE, self::GUEST . '@example.org', '--uid=' . self::GUEST, '--password-from-env', '--display-name=Guest');
		} finally {
			putenv('OC_PASS');
		}
		GuestDeckParityProbe::registerBackend($apps);
		$guest = Server::get(IUserManager::class)->get(self::GUEST)
			?? throw new RuntimeException('The guest account is not visible in the test process: ' . implode(' ', self::occ('user:info', self::GUEST)));
		// A guest gets no disk space by default; the sharing scenario uploads one small file.
		$guest->setQuota(self::GUEST_QUOTA);
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible(self::GUEST, true);
		$policy->setConnected(self::GUEST, true);
		$policy->setGrant(self::GUEST, 'files', 'share', true);
		// The editable received-share control requires the Files edit grant.
		$policy->setGrant(self::GUEST, 'files', 'edit', true);
		self::grantGuestControls($policy);
	}

	/**
	 * Delete this class's account and restore the Guests app lifecycle.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		self::$logins = [];
		try {
			self::occ('user:delete', self::GUEST);
		} finally {
			foreach (['whitelist', 'usewhitelist', 'hide_users', 'allow_external_storage'] as $key) {
				self::occ('config:app:delete', 'guests', $key);
			}
			if (self::$enabledHere) {
				self::occ('app:disable', 'guests');
			}
		}
		parent::tearDownAfterClass();
	}

	/**
	 * Release scenario resources before removing its external mount.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		try {
			$this->releaseGuestShares();
			if ($this->mountId !== null) {
				self::occ('files_external:delete', '--yes', (string)$this->mountId);
				$this->mountId = null;
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Configure the positive controls specific to the consuming test class.
	 *
	 * @param GrantPolicy $policy Policy of the disposable container.
	 * @return void
	 */
	abstract protected static function grantGuestControls(GrantPolicy $policy): void;

	/**
	 * Release class-specific shares; the parity scenario uses its native resource ledger instead.
	 *
	 * @return void
	 */
	protected function releaseGuestShares(): void {
	}

	/**
	 * Resolve the download and upload links of one confirmed checkout.
	 *
	 * @param McpClient $client The guest MCP client.
	 * @param string $path Canonical received-share path.
	 * @return array{string, string} Download and upload URLs.
	 */
	private function checkout(McpClient $client, string $path): array {
		$reply = $client->write('files_checkout', ['path' => $path]);
		$this->assertTrue($reply->ok(), 'files_checkout: ' . $reply->raw);
		$out = (array)$reply->json();
		return [Http::BASE . (string)parse_url((string)$out['download_url'], PHP_URL_PATH),
			Http::BASE . (string)parse_url((string)$out['upload_url'], PHP_URL_PATH)];
	}

	/**
	 * Apply the Guests settings needed by the current phase.
	 *
	 * @param array<string, string> $values Guests settings for this scenario.
	 * @return void
	 */
	private function guests(array $values): void {
		foreach ($values as $key => $value) {
			self::occ('config:app:set', 'guests', $key, '--value=' . $value);
		}
	}

	/**
	 * Build one MCP client for the disposable guest.
	 *
	 * @param string $kind Basic or Bearer authentication.
	 * @return McpClient Client bound to that login.
	 */
	private function mcp(string $kind): McpClient {
		return new McpClient(self::$logins[$kind] ??= $kind === 'bearer' ? LoginFactory::bearer(self::GUEST) : LoginFactory::basic(self::GUEST));
	}

	/**
	 * Record non-secret native and MCP comparison results.
	 *
	 * @param string $line Observation written into the run artifacts.
	 * @return void
	 */
	private function observe(string $line): void {
		file_put_contents('/tmp/mcp-it-observations.txt', '[' . self::class . '] ' . $line . "\n", FILE_APPEND);
	}

	/**
	 * Execute the native administrative fixture command inside the disposable container.
	 *
	 * @param string ...$arguments Native occ command and arguments.
	 * @return list<string> Output lines; a nonzero exit throws.
	 */
	private static function occ(string ...$arguments): array {
		$command = 'php ' . escapeshellarg((getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/occ') . ' --no-interaction '
			. implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
		exec($command, $output, $status);
		if ($status !== 0) {
			throw new RuntimeException('occ ' . implode(' ', $arguments) . " failed ($status): " . implode("\n", $output));
		}
		return $output;
	}
}
