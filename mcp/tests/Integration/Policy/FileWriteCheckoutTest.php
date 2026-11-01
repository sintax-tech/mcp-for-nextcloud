<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\OAuth\Storage\TokenHasher;
use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{Http, LoginFactory, McpClient};
use OCP\Config\IUserConfig;
use OCP\Files\{Folder, IRootFolder};
use OCP\{IAppConfig, IConfig, Server};

/** Real HTTP PUT/POST links re-read policy and preserve capabilities on pre-spend refusal. */
final class FileWriteCheckoutTest extends IntegrationTestCase {
	private Folder $folder;
	private McpClient $mcp;
	/** @var array<string, string|null> Raw fixture state restored after each case. */
	private array $before = [];

	/** Create a unique native Files fixture and enable the existing user/grants through official services. */
	protected function setUp(): void {
		parent::setUp();
		$apps = Server::get(IAppConfig::class);
		$users = Server::get(IUserConfig::class);
		foreach ([GrantPolicy::SERVICE_KEY, FileWritePolicy::KEY] as $key) {
			$this->before[$key] = $apps->hasKey('mcp', $key, null) ? $apps->getValueString('mcp', $key, '', false) : null;
		}
		foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_files_create', 'grant_files_edit'] as $key) {
			$this->before[$key] = $users->hasKey(Fixtures::ALICE, 'mcp', $key) ? $users->getValueString(Fixtures::ALICE, 'mcp', $key) : null;
		}
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible(Fixtures::ALICE, true);
		$policy->setConnected(Fixtures::ALICE, true);
		$policy->setGrant(Fixtures::ALICE, 'files', 'create', true);
		$policy->setGrant(Fixtures::ALICE, 'files', 'edit', true);
		$apps->deleteKey('mcp', FileWritePolicy::KEY);
		$apps->clearCache(false);
		$this->folder = Server::get(IRootFolder::class)->getUserFolder(Fixtures::ALICE)->newFolder('mcp-type-' . bin2hex(random_bytes(6)));
		$this->mcp = new McpClient(LoginFactory::basic(Fixtures::ALICE));
	}

	/** Restore settings; this disposable fixture is removed by the test, never by application code. */
	protected function tearDown(): void {
		$config = Server::get(IConfig::class);
		foreach ([GrantPolicy::SERVICE_KEY, FileWritePolicy::KEY] as $key) {
			$this->before[$key] === null ? $config->deleteAppValue('mcp', $key) : $config->setAppValue('mcp', $key, $this->before[$key]);
		}
		foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_files_create', 'grant_files_edit'] as $key) {
			$this->before[$key] === null ? $config->deleteUserValue(Fixtures::ALICE, 'mcp', $key) : $config->setUserValue(Fixtures::ALICE, 'mcp', $key, $this->before[$key]);
		}
		Server::get(IAppConfig::class)->clearCache(false);
		Server::get(IUserConfig::class)->clearCache(Fixtures::ALICE, false);
		$this->folder->delete();
		parent::tearDown();
	}

	/**
	 * @param string $name Final target basename.
	 * @return string User-relative path.
	 */
	private function path(string $name): string {
		return '/' . $this->folder->getName() . '/' . $name;
	}

	/**
	 * @param string $tool Tool to confirm.
	 * @param string $name Resolved destination basename.
	 * @return string Capability URL without exposing it in test output.
	 */
	private function link(string $tool, string $name): string {
		$reply = $this->mcp->write($tool, ['path' => $this->path($name)]);
		self::assertTrue($reply->ok(), 'Link issuance must succeed');
		$data = $reply->json();
		return Http::BASE . parse_url($data[$tool === 'files_upload' ? 'uploadUrl' : 'upload_url'], PHP_URL_PATH);
	}

	/**
	 * @param string $method PUT or POST.
	 * @param string $url Issued route.
	 * @param string $bytes Raw bytes, independent of MIME/local name.
	 * @return int HTTP result.
	 */
	private function upload(string $method, string $url, string $bytes): int {
		return Http::request($method, $url, ['Content-Type: application/octet-stream'], $bytes)['status'];
	}

	/** PUT/POST create and checkout links minted before allowlist activation refuse without effects or spend. */
	public function testOldLinksAre403WithoutSpendAndOffStillAcceptsBinaryForPutAndPost(): void {
		foreach (['PUT', 'POST'] as $method) {
			foreach (['create', 'replace'] as $kind) {
				if ($method === 'PUT' && $kind === 'create') {
					Server::get(IAppConfig::class)->deleteKey('mcp', FileWritePolicy::KEY);
				} else {
					Server::get(FileWritePolicy::class)->save('off', [], false);
				}
				$name = strtolower($method) . '-' . $kind . '.bin';
				$original = null;
				if ($kind === 'replace') {
					$original = $this->folder->newFile($name, "original\0bytes");
					$before = [$original->getId(), $original->getEtag(), $original->getContent()];
				}
				$url = $this->link($kind === 'create' ? 'files_upload' : 'files_checkout', $name);
				Server::get(FileWritePolicy::class)->save('allowlist', ['txt'], false);
				self::assertSame(403, $this->upload($method, $url, "new\0binary"));
				$row = Server::get(CheckoutTokenStore::class)->find(Server::get(TokenHasher::class)->hash(basename($url)));
				self::assertNotNull($row);
				self::assertFalse($row->used);
				if ($kind === 'create') {
					self::assertFalse($this->folder->nodeExists($name));
				} else {
					$node = $this->folder->get($name);
					self::assertSame($before, [$node->getId(), $node->getEtag(), $node->getContent()]);
				}
				Server::get(FileWritePolicy::class)->save('off', [], false);
				self::assertSame($kind === 'create' ? 201 : 200, $this->upload($method, $url, "new\0binary"));
				self::assertSame("new\0binary", $this->folder->get($name)->getContent());
				self::assertSame(410, $this->upload($method, $url, 'again'));
			}
		}
	}

	/** txt alone permits the mandatory backup; forbidden public extensions leave no new node. */
	public function testAllowlistCreateAndCheckoutKeepVerifiedInternalBakForPutAndPost(): void {
		Server::get(FileWritePolicy::class)->save('allowlist', ['txt'], false);
		foreach (['PUT', 'POST'] as $method) {
			$name = strtolower($method) . '.txt';
			$url = $this->link('files_upload', $name);
			self::assertSame(201, $this->upload($method, $url, 'original'));
			$reply = Http::request($method, $this->link('files_checkout', $name), ['Content-Type: application/octet-stream'], 'changed');
			self::assertSame(200, $reply['status']);
			$data = json_decode($reply['body'], true, 512, JSON_THROW_ON_ERROR);
			self::assertStringEndsWith('.bak', $data['backup']);
			$home = Server::get(IRootFolder::class)->getUserFolder(Fixtures::ALICE);
			self::assertSame('original', $home->get($data['backup'])->getContent());
			self::assertSame('changed', $this->folder->get($name)->getContent());
		}
		foreach (['public.bak', 'public.vcf', 'public.bin'] as $name) {
			foreach ([false, true] as $confirmed) {
				$args = ['path' => $this->path($name)];
				$reply = $confirmed ? $this->mcp->write('files_upload', $args) : $this->mcp->tool('files_upload', $args);
				self::assertTrue($reply->isError());
				self::assertFalse($this->folder->nodeExists($name));
			}
		}
	}
}
