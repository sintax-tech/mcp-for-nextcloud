<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use ReflectionProperty;
use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\Controller\CheckoutController;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Tools\Files\FileCreation;
use OCA\Mcp\Tools\Files\FilesModule;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

/** Stages raw bytes without changing production route-token resolution. */
final class PolicyUploadController extends CheckoutController {
	/** @var string Raw request body. */
	public string $bytes = 'new bytes';
	/** @var string Route credential, separate from body parameters. */
	public string $token = '';

	/** @return resource Readable raw body. */
	protected function inputStream() {
		$stream = fopen('php://memory', 'w+');
		fwrite($stream, $this->bytes);
		rewind($stream);
		return $stream;
	}

	/** @return string The route credential. */
	protected function routeToken(): ?string {
		return $this->token;
	}
}

/** Real evaluator at MCP plans, confirmation, token consumption and both storage sinks. */
final class FileWritePolicyBoundariesTest extends FilesToolsTestCase {
	private FileTypePolicyFixture $types;
	private FileCreation $creation;

	/** Wire the same mutable policy into each independent production boundary. */
	protected function setUp(): void {
		parent::setUp();
		$this->user->method('isEnabled')->willReturn(true);
		$this->types = new FileTypePolicyFixture();
		$this->creation = (new ReflectionProperty(FilesModule::class, 'creation'))->getValue($this->module);
		foreach ([$this->module, $this->creation] as $service) {
			(new ReflectionProperty($service, 'fileTypes'))->setValue($service, $this->types->guard($this));
		}
	}

	/**
	 * @param string $name Constructor collaborator of FilesModule.
	 * @return mixed The already wired service.
	 */
	private function dependency(string $name): mixed {
		return (new ReflectionProperty(FilesModule::class, $name))->getValue($this->module);
	}

	/**
	 * @param string $token Single-use capability.
	 * @param bool $changeOnSpend Change policy at the atomic spend seam.
	 * @return PolicyUploadController The actual HTTP controller with staged raw input.
	 */
	private function controller(string $token, bool $changeOnSpend = false): PolicyUploadController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['Content-Type', 'application/octet-stream'], ['Content-Length', '9']]);
		$session = $this->createMock(IUserSession::class);
		$policy = $this->sharePolicy;
		$policy->setGlobalEnabled(true);
		$policy->setEligible('alice', true);
		$policy->setConnected('alice', true);
		$policy->setGrant('alice', 'files', 'create', true);
		$policy->setGrant('alice', 'files', 'edit', true);
		$store = $this->store;
		if ($changeOnSpend) {
			$store = $this->createMock(CheckoutTokenStore::class);
			$store->method('find')->willReturnCallback(fn (string $hash) => $this->store->find($hash));
			$store->method('consume')->willReturnCallback(function (CheckoutToken $row, int $now): bool {
				$spent = $this->store->consume($row, $now);
				$this->types->allow([]);
				return $spent;
			});
		}
		$controller = new PolicyUploadController('mcp', $request, $this->dependency('rootFolder'), $session,
			$this->temp, $this->time, $policy, $store, new TokenHasher($this->config->mock($this)), $this->dependency('checkout'),
			$this->dependency('backup'), $this->dependency('guard'), $this->dependency('accessInfo'), $this->logger,
			userManager: $this->users, visibilityGuard: $this->visibilityGuard, creation: $this->creation,
			locks: $this->locks->service($this), fileTypes: $this->types->guard($this));
		$controller->token = $token;
		return $controller;
	}

	/** All three tools refuse both plan and confirm before minting or writing. */
	public function testPlansAndConfirmationsUseFinalNameAndNoPublicBackupSuffixBypass(): void {
		$this->types->allow(['txt']);
		foreach ([['files_create', '/Documentos/new.md', ['content' => 'x']], ['files_upload', '/Documentos/new.bin', []],
			['files_upload', '/Documentos/public.bak', []], ['files_upload', '/Documentos/public.vcf', []],
			['files_checkout', '/Documentos/ata.md', []]] as [$tool, $path, $extra]) {
			foreach ([false, true] as $confirm) {
				try {
					$confirm ? $this->json($tool, ['path' => $path] + $extra) : $this->plan($tool, ['path' => $path] + $extra);
					self::fail('Denied final name accepted');
				} catch (FileTypeDenied) {
					self::assertSame([], $this->tree->ops);
					self::assertSame([], $this->store->rows);
				}
			}
		}
	}

	/** Each confirmation reads a fresh policy instead of retaining the successful plan's snapshot. */
	public function testPolicyChangeBetweenPlanAndConfirmationRefusesEveryTool(): void {
		foreach ([['files_create', '/Documentos/new.txt', ['content' => 'x']], ['files_upload', '/Documentos/new.txt', []],
			['files_checkout', '/Documentos/ata.md', []]] as [$tool, $path, $extra]) {
			$this->types->raw = null;
			$this->plan($tool, ['path' => $path] + $extra);
			$this->types->allow([]);
			try {
				$this->json($tool, ['path' => $path] + $extra);
				self::fail('A plan bypassed changed authorization');
			} catch (FileTypeDenied) {
				self::assertSame([], $this->tree->ops);
				self::assertSame([], $this->store->rows);
			}
		}
	}

	/** The writer defends direct callers before creating even an empty node. */
	public function testDirectCreationSinkRefusesBeforeNewFile(): void {
		$this->types->allow(['txt']);
		try {
			$this->creation->write($this->tree->rootFolder()->get('Documentos'), 'blocked.bin', 'bytes', 'alice');
			self::fail('Direct writer bypassed policy');
		} catch (FileTypeDenied) {
			self::assertSame([], $this->tree->ops);
			self::assertFalse($this->tree->rootFolder()->get('Documentos')->nodeExists('blocked.bin'));
		}
	}

	/** A changed policy after empty creation refuses putContent, preserving the existing no-deletion contract. */
	public function testCreationRechecksBeforePutContentWithoutDeletingEmptyFile(): void {
		$this->types->allow(['txt']);
		$this->tree->afterCreate = fn () => $this->types->allow([]);
		try {
			$this->creation->write($this->tree->rootFolder()->get('Documentos'), 'empty.txt', 'bytes', 'alice');
			self::fail('Content sink bypassed policy');
		} catch (FileTypeDenied) {
			self::assertSame('', $this->tree->rootFolder()->get('Documentos')->get('empty.txt')->getContent());
		}
	}

	/** Tokens emitted under off are refused without spend, and work after off is restored. */
	public function testOldCreateAndCheckoutTokensRevalidateBeforeSpendAndBackup(): void {
		foreach (['create', 'replace'] as $kind) {
			$this->types->raw = null;
			$link = $kind === 'create' ? $this->json('files_upload', ['path' => '/Documentos/new.bin'])
				: $this->json('files_checkout', ['path' => '/Documentos/ata.md']);
			$url = $link[$kind === 'create' ? 'uploadUrl' : 'upload_url'];
			$controller = $this->controller(basename($url));
			$this->types->allow(['txt']);
			$before = $this->tree->ops;
			self::assertSame(403, $controller->upload()->getStatus());
			self::assertSame($before, $this->tree->ops);
			$row = $this->store->find((new TokenHasher($this->config->mock($this)))->hash(basename($url)));
			self::assertFalse($row->used);
			$this->types->raw = null;
			self::assertSame($kind === 'create' ? 201 : 200, $controller->upload()->getStatus());
		}
	}

	/** Both HTTP creation and replacement map defensive refusals after spend to 403 before any backup/write. */
	public function testPolicyChangeAtSpendIs403ForBothControllersAndCostsToken(): void {
		foreach (['create', 'replace'] as $kind) {
			$this->types->raw = null;
			$link = $kind === 'create' ? $this->json('files_upload', ['path' => '/Documentos/new.bin'])
				: $this->json('files_checkout', ['path' => '/Documentos/ata.md']);
			$token = basename($link[$kind === 'create' ? 'uploadUrl' : 'upload_url']);
			$before = $this->tree->ops;
			self::assertSame(403, $this->controller($token, true)->upload()->getStatus());
			self::assertTrue($this->store->find((new TokenHasher($this->config->mock($this)))->hash($token))->used);
			self::assertSame($before, $this->tree->ops);
		}
	}

	/** A policy change during mandatory backup is caught by the guard immediately before putContent. */
	public function testPolicyChangeDuringBackupRefusesContentSinkAndKeepsOriginalAndBackup(): void {
		$this->tree->addFile('/alice/files/Documentos/a.txt', 'original');
		$this->types->allow(['txt']);
		$link = $this->json('files_checkout', ['path' => '/Documentos/a.txt']);
		$token = basename($link['upload_url']);
		$original = $this->tree->rootFolder()->get('Documentos/a.txt');
		$before = [$original->getId(), $original->getEtag(), $original->getContent()];
		$backupPath = '';
		$this->tree->afterCreate = function (string $path) use (&$backupPath): void {
			if (str_ends_with($path, '.bak')) {
				$backupPath = substr($path, strlen('/alice/files/'));
				$this->types->allow([]);
			}
		};

		$response = $this->controller($token)->upload();

		self::assertSame(403, $response->getStatus());
		self::assertTrue($this->store->find((new TokenHasher($this->config->mock($this)))->hash($token))->used);
		self::assertNotSame('', $backupPath);
		self::assertTrue($this->tree->rootFolder()->nodeExists($backupPath));
		self::assertSame('original', $this->tree->rootFolder()->get($backupPath)->getContent());
		$current = $this->tree->rootFolder()->get('Documentos/a.txt');
		self::assertSame($before, [$current->getId(), $current->getEtag(), $current->getContent()]);
	}

	/** A permitted txt creates a verified mandatory .bak without granting public .bak files. */
	public function testTxtCheckoutKeepsMandatoryBackupOutsidePolicy(): void {
		$this->tree->addFile('/alice/files/Documentos/a.txt', 'original');
		$this->types->allow(['txt']);
		$link = $this->json('files_checkout', ['path' => '/Documentos/a.txt']);
		$response = $this->controller(basename($link['upload_url']))->upload();
		self::assertSame(200, $response->getStatus());
		$data = json_decode($response->getData(), true);
		self::assertStringEndsWith('.bak', $data['backup']);
		self::assertSame('original', $this->tree->rootFolder()->get($data['backup'])->getContent());
		self::assertSame('new bytes', $this->tree->rootFolder()->get('Documentos/a.txt')->getContent());
	}

	/** Invalid persisted policy fails closed rather than silently treating it as off. */
	public function testUnreadablePolicyLeavesTokenUsable(): void {
		$link = $this->json('files_upload', ['path' => '/Documentos/new.bin']);
		$token = basename($link['uploadUrl']);
		$this->types->raw = '{';
		self::assertSame(403, $this->controller($token)->upload()->getStatus());
		self::assertFalse($this->store->find((new TokenHasher($this->config->mock($this)))->hash($token))->used);
		self::assertSame([], $this->tree->ops);
	}
}
