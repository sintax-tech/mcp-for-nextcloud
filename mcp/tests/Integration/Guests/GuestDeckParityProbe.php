<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Guests;

use OCA\Guests\AppInfo\Application as GuestsApplication;
use OCA\Guests\UserBackend;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Constants;
use OCP\Share\IShare;
use OCP\Server;
use RuntimeException;

/**
 * Native WebDAV uses the same UID's app password; the app's OAuth token has an MCP-only audience.
 */
final class GuestDeckParityProbe {
	/**
	 * Register the native guest backend in a CLI actor without booting Guests' HTTP URI restrictions.
	 *
	 * @param IAppManager $apps Native app paths and enabled app loader.
	 * @return void
	 */
	public static function registerBackend(IAppManager $apps): void {
		foreach (Server::get(IUserManager::class)->getBackends() as $backend) {
			if ($backend instanceof UserBackend) {
				return;
			}
		}
		$guestPath = $apps->getAppPath('guests');
		if (!class_exists(UserBackend::class)) {
			if (is_file($guestPath . '/composer/autoload.php')) {
				require_once $guestPath . '/composer/autoload.php';
			}
			spl_autoload_register(static function (string $class) use ($guestPath): void {
				$prefix = 'OCA\\Guests\\';
				if (str_starts_with($class, $prefix)) {
					$file = $guestPath . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
					if (is_file($file)) {
						require $file;
					}
				}
			});
		}
		Server::get(IUserManager::class)->registerBackend(
			(new GuestsApplication())->getContainer()->get(UserBackend::class));
	}

	/**
	 * Prepare an editable received share through the owner's native OCS HTTP route, never the guest MCP.
	 *
	 * @param Credential $owner Native app-password login of the non-guest fixture owner.
	 * @param string $path Owner-relative canonical external path.
	 * @param string $recipient Native guest UID.
	 * @return array{id:string,fileId:int,permissions:int,recipient:string} Native share identity for preservation checks.
	 */
	public static function editableShare(Credential $owner, string $path, string $recipient): array {
		$reply = Http::request('POST', Http::BASE . '/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json',
			['Authorization: ' . $owner->authorization(), 'OCS-APIRequest: true', 'Content-Type: application/x-www-form-urlencoded'],
			http_build_query(['path' => $path, 'shareType' => IShare::TYPE_USER, 'shareWith' => $recipient, 'permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE]));
		$out = json_decode($reply['body'], true);
		if ($reply['status'] !== 200 || ($out['ocs']['meta']['statuscode'] ?? null) !== 200) {
			throw new RuntimeException('Native owner share fixture failed: ' . $reply['body']);
		}
		$data = $out['ocs']['data'];
		return ['id' => 'ocinternal:' . $data['id'], 'fileId' => (int)$data['file_source'],
			'permissions' => (int)$data['permissions'], 'recipient' => $data['share_with']];
	}

	/**
	 * Compare native identity and bytes with Files/read at the exact same URI.
	 *
	 * @param Credential $native Native app-password login of the same guest used by the MCP client.
	 * @param McpClient $mcp Basic or app OAuth login whose Files response is being compared.
	 * @param string $path Exact native user-relative path; no inferred storage provenance.
	 * @return array<string, mixed> Status, native identity and hashes, with no credentials or checkout tokens.
	 */
	public static function read(Credential $native, McpClient $mcp, string $path): array {
		$prop = self::dav($native, 'PROPFIND', $path);
		$get = self::dav($native, 'GET', $path);
		$xml = $prop['status'] === 207 ? simplexml_load_string($prop['body']) : false;
		$ids = $xml === false ? [] : $xml->xpath('//*[local-name()="fileid"]');
		$reply = $mcp->tool('files_read', ['path' => $path]);
		return ['path' => $path, 'davAuth' => 'Basic (same UID)', 'propfind' => $prop['status'],
			'get' => $get['status'], 'fileId' => isset($ids[0]) ? (int)(string)$ids[0] : null,
			'davHash' => $get['status'] === 200 ? hash('sha256', $get['body']) : null,
			'mcpRead' => $reply->ok(), 'mcpHash' => $reply->ok()
				? hash('sha256', $reply->result()['content'][0]['text']) : null];
	}

	/**
	 * Send a native WebDAV request authenticated as the parity scenario guest.
	 *
	 * @param Credential $native Native app-password login of the guest.
	 * @param string $method Native WebDAV method.
	 * @param string $path Exact user-relative path.
	 * @param string|null $body Existing bytes for a non-destructive write control.
	 * @return array{status:int,headers:string,body:string} Native response.
	 */
	public static function dav(Credential $native, string $method, string $path, ?string $body = null): array {
		$url = Http::BASE . '/remote.php/dav/files/' . rawurlencode($native->uid)
			. '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
		$headers = ['Authorization: ' . $native->authorization()];
		if ($method === 'PROPFIND') {
			$headers[] = 'Depth: 0';
			$headers[] = 'Content-Type: application/xml';
			$body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
				. '<d:prop><oc:fileid/><d:getetag/></d:prop></d:propfind>';
		}
		return Http::request($method, $url, $headers, $body);
	}
}
