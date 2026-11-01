<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\Controller\CheckoutLimitController;
use OCA\Mcp\Controller\FileWritePolicyController;
use OCA\Mcp\Controller\LogsPrivacyController;
use OCA\Mcp\Controller\TagsController;
use OCA\Mcp\OAuth\Storage\TokenHasher;
use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the administrator settings routes that write one setting each (RE-01): `logs_privacy#update`,
 * `file_write_policy#update`, `checkout_limit#update` and `tags#update`. Every controller is the real one over its real
 * service and an in-memory configuration; the effect is the stored configuration, recorded after every request. Each
 * transcript has the accepted values, the refused ones (nothing written) and, where the controller has one, the 503 of
 * a store that cannot be read.
 */
final class AdminSettingsEffectsContractTest extends TestCase {
	private InMemoryConfig $config;
	private InMemoryAppConfig $appConfig;
	/** @var array<string, mixed> JSON body of the next request */
	private array $body = [];
	/** @var list<string> ids of the system tags that exist */
	private array $tags = ['10', '11'];
	private bool $tagsDown = false;

	/** @return void the in-memory configurations every controller writes to */
	protected function setUp(): void {
		$this->config = new InMemoryConfig();
		$this->appConfig = new InMemoryAppConfig();
	}

	/** @return void logs_privacy#update: the switch is stored only for a boolean */
	public function testLogsPrivacy(): void {
		$controller = fn (): LogsPrivacyController => new LogsPrivacyController('mcp', $this->request(),
			new LogFileNames($this->appConfig->worker($this), $this->guard()));
		$this->freeze('settings.logs-privacy', 'logs_privacy#update', [
			['a string is refused: 400', ['hideFileNames' => 'false']],
			['off', ['hideFileNames' => false]],
			['on again', ['hideFileNames' => true]],
		], static fn () => $controller()->update());
	}

	/** @return void file_write_policy#update: canonical allowlist stored; invalid fields write nothing */
	public function testFileWritePolicy(): void {
		$controller = fn (): FileWritePolicyController => new FileWritePolicyController('mcp', $this->request(),
			new FileWritePolicy($this->appConfig->worker($this), $this->createMock(IDBConnection::class)));
		$this->freeze('settings.file-write-policy', 'file_write_policy#update', [
			['an unknown mode is refused: 400', ['mode' => 'denylist', 'extensions' => ['md'], 'allowExtensionless' => false]],
			['a non-boolean extensionless flag: 400', ['mode' => 'allowlist', 'extensions' => ['md'], 'allowExtensionless' => 'true']],
			['a wildcard extension: 400', ['mode' => 'allowlist', 'extensions' => ['*'], 'allowExtensionless' => false]],
			['allowlist saved in canonical form', ['mode' => 'allowlist', 'extensions' => ".TXT,md\n", 'allowExtensionless' => true]],
			['off', ['mode' => 'off', 'extensions' => [], 'allowExtensionless' => false]],
		], static fn () => $controller()->update());
	}

	/** @return void checkout_limit#update: the size is stored in bytes, only within 1..MAX_MIB */
	public function testCheckoutLimit(): void {
		$controller = function (): CheckoutLimitController {
			$config = $this->config->mock($this);
			$checkout = new CheckoutService($this->createMock(IURLGenerator::class), $config, $this->createMock(ITimeFactory::class),
				new TokenHasher($config), $this->createMock(CheckoutTokenStore::class), $this->createMock(IAppManager::class),
				$this->createMock(IUserManager::class));
			return new CheckoutLimitController('mcp', $this->request(), $checkout, $config);
		};
		$this->freeze('settings.checkout-limit', 'checkout_limit#update', [
			['zero is refused: 400', ['mib' => 0]],
			['above the maximum is refused: 400', ['mib' => CheckoutLimitController::MAX_MIB + 1]],
			['a string is refused: 400', ['mib' => '5']],
			['5 MiB stored in bytes', ['mib' => 5]],
		// phpBytes/effectiveBytes come from the upload limits of php:8.3-cli, the image every contract runs in.
		], static fn () => $controller()->update());
	}

	/** @return void tags#update: only existing tags are stored; a tag service that cannot be read is a 503 */
	public function testHiddenTags(): void {
		$controller = function (): TagsController {
			$manager = $this->createMock(ISystemTagManager::class);
			$manager->method('getTagsByIds')->willReturnCallback(function (array $ids): array {
				if ($this->tagsDown) {
					throw new \RuntimeException('tags down');
				}
				return array_map(fn (string $id): ISystemTag => $this->tag($id), array_values(array_intersect($ids, $this->tags)));
			});
			$manager->method('getAllTags')->willReturnCallback(fn (): array => $this->tagsDown ? throw new \RuntimeException('tags down')
				: array_map(fn (string $id): ISystemTag => $this->tag($id), $this->tags));
			return new TagsController('mcp', $this->request(), $this->guard($manager), $manager);
		};
		$steps = [
			['not a list: 400', ['tagIds' => ['a' => 10]]],
			['a non-numeric id: 400', ['tagIds' => ['x']]],
			['existing tags stored, the unknown one dropped', ['tagIds' => [10, '11', 99]]],
		];
		$this->freeze('settings.hidden-tags', 'tags#update', $steps, static fn () => $controller()->update());
		$this->tagsDown = true;
		$this->freeze('settings.hidden-tags-unavailable', 'tags#update', [
			['tags cannot be checked: 503, the previous selection is kept', ['tagIds' => [10]]],
		], static fn () => $controller()->update());
	}

	/**
	 * Sends each body to a fresh controller and freezes the transcript.
	 *
	 * @param string $name golden file name
	 * @param string $route route name
	 * @param list<array{0:string, 1:array<string, mixed>}> $steps step name and JSON body
	 * @param \Closure(): \OCP\AppFramework\Http\Response $call the controller method of one request
	 * @return void
	 */
	private function freeze(string $name, string $route, array $steps, \Closure $call): void {
		$transcript = new ControllerTranscript($this, $name, fn (): array => $this->state());
		foreach ($steps as [$step, $body]) {
			$this->body = $body;
			$before = $this->state();
			$answer = $transcript->record($step, ['route' => $route, 'user' => 'root', 'body' => $body], $call());
			if ($answer['status'] !== 200) {
				$this->assertEquals($before, $this->state(), $step . ': a refusal wrote something');
			}
		}
		$transcript->assertGolden();
	}

	/** @return array<string, mixed> every stored app value of mcp, in both configuration stores */
	private function state(): array {
		$app = $this->config->app['mcp'] ?? [];
		ksort($app);
		$stored = $this->appConfig->stored;
		ksort($stored);
		return ['config' => $app, 'appConfig' => $stored];
	}

	/**
	 * @param ISystemTagManager|null $manager the tag service, when the guard checks tags
	 * @return VisibilityGuard the real guard over the in-memory configuration
	 */
	private function guard(?ISystemTagManager $manager = null): VisibilityGuard {
		return new VisibilityGuard($this->config->mock($this), $this->createMock(ISystemTagObjectMapper::class), null, $manager);
	}

	/**
	 * @param string $id tag id
	 * @return ISystemTag a tag with that id
	 */
	private function tag(string $id): ISystemTag {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn($id);
		return $tag;
	}

	/** @return IRequest a request whose parameters are the body of the next call */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => array_key_exists($key, $this->body)
			? $this->body[$key] : $default);
		return $request;
	}
}
