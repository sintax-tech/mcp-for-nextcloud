<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use RuntimeException;

/**
 * Push devices registered through the Notifications app's own OCS endpoints, delivering to a local fake (issue #26).
 *
 * The proxy device uses `/api/v2/push` with `http://localhost:<port>/`. The web push subscription uses
 * `/api/v2/webpush` and the official activation; its https endpoint is a closed local port. Newer Notifications
 * versions check both hosts with `IRemoteHostValidator`, which accepts a local host only while
 * `allow_local_remote_servers` is on, and serve web push only with the app's `webpush_enabled` switch. Both values
 * are changed in the throwaway test instance only and restored on close.
 */
final class PushDeviceFixture {
	/** Port of the throwaway proxy started by {@see startProxy()}. */
	public const PROXY_PORT = 18926;
	/** Local port nothing listens on, for deliveries that fail at the network. */
	public const CLOSED_PORT = 18927;
	/** Prefix of the files the proxy router shares with the test. */
	private const STATE = '/tmp/mcp-it-push';

	/** @var resource|null Running `php -S` process of the proxy. */
	private $proxy = null;
	/** @var list<array{credential: Credential, kind: string}> Registered devices, unregistered on close. */
	private array $devices = [];
	/** System value of `allow_local_remote_servers` before this fixture, null when unset. */
	private mixed $localServers = null;
	/** Whether this fixture changed `allow_local_remote_servers`. */
	private bool $changedLocalServers = false;
	/** Notifications' `webpush_enabled` before this fixture, null when unset or not changed. */
	private ?bool $webPushSwitch = null;
	/** Whether this fixture changed `webpush_enabled`. */
	private bool $changedWebPushSwitch = false;

	/**
	 * Lets the Notifications app accept local push hosts in this test instance.
	 *
	 * @return void
	 */
	private function allowLocalHosts(): void {
		if ($this->changedLocalServers) {
			return;
		}
		$config = Server::get(IConfig::class);
		$this->localServers = $config->getSystemValue('allow_local_remote_servers', null);
		$config->setSystemValue('allow_local_remote_servers', true);
		$this->changedLocalServers = true;
	}

	/**
	 * Turns on the Notifications app's web push switch in this test instance.
	 *
	 * @return void
	 */
	private function enableWebPush(): void {
		if ($this->changedWebPushSwitch) {
			return;
		}
		$config = Server::get(IAppConfig::class);
		$this->webPushSwitch = $config->hasKey('notifications', 'webpush_enabled')
			? $config->getValueBool('notifications', 'webpush_enabled') : null;
		$config->setValueBool('notifications', 'webpush_enabled', true);
		$this->changedWebPushSwitch = true;
	}

	/**
	 * Starts the local proxy that answers with the given status.
	 *
	 * @param int $status HTTP status every proxy request receives.
	 * @return void
	 * @throws RuntimeException When the proxy does not listen.
	 */
	public function startProxy(int $status): void {
		$this->proxyStatus($status);
		@unlink(self::STATE . '-requests.jsonl');
		if ($this->proxy === null) {
			$this->proxy = proc_open(['env', 'MCP_IT_PUSH_STATE=' . self::STATE, PHP_BINARY, '-S', '127.0.0.1:' . self::PROXY_PORT,
				__DIR__ . '/push-proxy.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'],
					2 => ['file', '/dev/null', 'w']], $pipes);
		}
		for ($try = 0; $try < 50; $try++) {
			$socket = @fsockopen('127.0.0.1', self::PROXY_PORT, $errno, $error, 0.2);
			if ($socket !== false) {
				fclose($socket);
				return;
			}
			usleep(100000);
		}
		throw new RuntimeException('The local push proxy did not start');
	}

	/** @param int $status HTTP status the proxy answers from now on. @return void */
	public function proxyStatus(int $status): void {
		file_put_contents(self::STATE . '-status', (string)$status);
	}

	/** @return list<array{method: string, path: string, bytes: int, at: float}> Requests the proxy received so far. */
	public function proxyRequests(): array {
		$lines = @file(self::STATE . '-requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
	}

	/**
	 * Registers a proxy push device for the account, as a mobile client does with its app password.
	 *
	 * @param string $uid Account receiving pushes.
	 * @param int $port Local port of the proxy the device names.
	 * @param string $path Path prefix of the proxy URL, so the requests of each device can be told apart.
	 * @return array{status: int, headers: string, body: string} OCS answer of the registration.
	 */
	public function registerProxyDevice(string $uid, int $port, string $path = ''): array {
		$this->allowLocalHosts();
		$credential = LoginFactory::basic($uid);
		$key = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]));
		$answer = $this->ocs($credential, 'POST', '/push', ['pushTokenHash' => hash('sha512', random_bytes(32)),
			'devicePublicKey' => (string)$key['key'], 'proxyServer' => 'http://localhost:' . $port . '/' . ($path === '' ? '' : $path . '/')]);
		$this->devices[] = ['credential' => $credential, 'kind' => 'push'];
		return $answer;
	}

	/**
	 * Registers and activates a web push subscription whose endpoint is a closed local https port.
	 *
	 * @param string $uid Account receiving pushes.
	 * @return array{register: array{status: int, headers: string, body: string}, activate: array{status: int, headers: string, body: string}|null}
	 */
	public function registerWebPush(string $uid): array {
		$this->allowLocalHosts();
		$this->enableWebPush();
		$credential = LoginFactory::basic($uid);
		$ec = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]));
		$point = "\x04" . str_pad($ec['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['ec']['y'], 32, "\0", STR_PAD_LEFT);
		$b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
		$register = $this->ocs($credential, 'POST', '/webpush', ['endpoint' => 'https://127.0.0.1:' . self::CLOSED_PORT . '/wp/'
			. bin2hex(random_bytes(8)), 'uaPublicKey' => $b64($point), 'auth' => $b64(random_bytes(16)), 'appTypes' => 'all']);
		$this->devices[] = ['credential' => $credential, 'kind' => 'webpush'];
		$activation = $this->activationToken($uid);
		$activate = $activation === null ? null : $this->ocs($credential, 'POST', '/webpush/activate', ['activationToken' => $activation]);
		return ['register' => $register, 'activate' => $activate];
	}

	/**
	 * Reads the activation token the registration stored, standing in for the browser that decrypts the push.
	 *
	 * @param string $uid Account of the subscription.
	 * @return string|null Token of the newest subscription, null when none was stored.
	 */
	private function activationToken(string $uid): ?string {
		$q = Server::get(IDBConnection::class)->getQueryBuilder();
		$token = $q->select('activation_token')->from('notifications_webpush')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))->orderBy('id', 'DESC')->setMaxResults(1)
			->executeQuery()->fetchOne();
		return is_string($token) && $token !== '' ? $token : null;
	}

	/**
	 * Calls an OCS route of the Notifications app with an app password.
	 *
	 * @param Credential $credential App password of the device owner.
	 * @param string $method HTTP verb.
	 * @param string $path Route below `/api/v2`.
	 * @param array<string, string> $form Form parameters.
	 * @return array{status: int, headers: string, body: string} Raw answer.
	 */
	private function ocs(Credential $credential, string $method, string $path, array $form = []): array {
		return Http::request($method, Http::BASE . '/ocs/v2.php/apps/notifications/api/v2' . $path . '?format=json', [
			'Authorization: ' . $credential->authorization(), 'OCS-APIRequest: true', 'Accept: application/json',
			'Content-Type: application/x-www-form-urlencoded'], http_build_query($form));
	}

	/** Unregisters every device, stops the proxy and restores both switches. @return void */
	public function close(): void {
		foreach (array_reverse($this->devices) as $device) {
			$this->ocs($device['credential'], 'DELETE', '/' . $device['kind']);
		}
		$this->devices = [];
		if ($this->proxy !== null) {
			proc_terminate($this->proxy);
			proc_close($this->proxy);
			$this->proxy = null;
		}
		foreach (['-status', '-requests.jsonl'] as $suffix) {
			@unlink(self::STATE . $suffix);
		}
		if ($this->changedLocalServers) {
			$config = Server::get(IConfig::class);
			$this->localServers === null ? $config->deleteSystemValue('allow_local_remote_servers')
				: $config->setSystemValue('allow_local_remote_servers', $this->localServers);
			$this->changedLocalServers = false;
		}
		if ($this->changedWebPushSwitch) {
			$config = Server::get(IAppConfig::class);
			$this->webPushSwitch === null ? $config->deleteKey('notifications', 'webpush_enabled')
				: $config->setValueBool('notifications', 'webpush_enabled', $this->webPushSwitch);
			$this->changedWebPushSwitch = false;
		}
	}
}
