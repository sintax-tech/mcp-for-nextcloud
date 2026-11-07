<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Audit of class names that outlive a request (RE-01), frozen in `golden/persisted-names.json`.
 *
 * `persisted` are the names Nextcloud stores or resolves from outside the code: background jobs (`oc_jobs.class`),
 * repair steps, the occ command and the settings of info.xml, and the migrations (`oc_migrations` keys the version
 * of each `OCA\Mcp\Migration\Version*` class). Renaming one of them breaks an existing installation, so they keep
 * their name through the restructuring. `di` are the keys Application::register() binds and the listeners and
 * middleware it registers: they are not stored anywhere, but moving one of them changes this list in the same
 * commit as the move.
 */
final class PersistedNamesContractTest extends TestCase {
	/** Golden file of the audit. */
	private const GOLDEN = __DIR__ . '/golden/persisted-names.json';
	/** App manifest. */
	private const INFO = __DIR__ . '/../../../appinfo/info.xml';
	/** Directory of the migrations. */
	private const MIGRATIONS = __DIR__ . '/../../../lib/Migration/';

	/** @return void every persisted name exists, and the persisted and DI names equal the golden */
	public function testPersistedNamesAndDiKeysAreUnchanged(): void {
		$persisted = $this->persisted();
		foreach ($persisted as $kind => $classes) {
			foreach ($classes as $class) {
				$this->assertTrue(class_exists($class), $kind . ': ' . $class . ' does not exist');
			}
		}
		GoldenFile::assertMatches($this, self::GOLDEN, ['persisted' => $persisted, 'di' => $this->registrations()]);
	}

	/** @return array<string, list<string>> class names Nextcloud stores or resolves from info.xml, by kind */
	private function persisted(): array {
		$info = simplexml_load_file(self::INFO);
		$list = static fn (string $xpath): array => array_map('strval', $info->xpath($xpath) ?: []);
		$migrations = array_map(static fn (string $file): string => 'OCA\\Mcp\\Migration\\' . basename($file, '.php'),
			glob(self::MIGRATIONS . 'Version*.php') ?: []);
		sort($migrations);
		return [
			'background-jobs (oc_jobs.class)' => $list('/info/background-jobs/job'),
			'repair-steps' => $list('/info/repair-steps/*/step'),
			'commands' => $list('/info/commands/command'),
			'settings' => $list('/info/settings/*'),
			'migrations (oc_migrations.version)' => $migrations,
		];
	}

	/** @return array<string, mixed> what Application::register() binds, in registration order */
	private function registrations(): array {
		$services = [];
		$listeners = [];
		$middleware = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(static function (string $name) use (&$services): void {
			$services[] = $name;
		});
		$context->method('registerEventListener')->willReturnCallback(static function (string $event, string $listener, int $priority = 0) use (&$listeners): void {
			$listeners[] = $event . ' -> ' . $listener . ' @' . $priority;
		});
		$context->method('registerMiddleware')->willReturnCallback(static function (string $class, bool $global = false) use (&$middleware): void {
			$middleware[] = $class . ($global ? ' (global)' : '');
		});
		// App::__construct() needs a running server; register() does not.
		(new ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);
		return ['registerService' => $services, 'registerEventListener' => $listeners, 'registerMiddleware' => $middleware,
			'MODULES' => Application::MODULES];
	}
}
