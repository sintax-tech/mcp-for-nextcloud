<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Integration\PollsMutationSurface;
use OCA\Mcp\Modules\Polls\Integration\PollsMutationProbe;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\{DataProvider, RunTestsInSeparateProcesses, PreserveGlobalState};
use PHPUnit\Framework\TestCase;

/** Reflects original pinned class declarations without constructing any native service or touching a database. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PollsMutationShapeTest extends TestCase {
	/** @return iterable<string,array{string,?string,?string}> Four positives and each missing required mutation member. */
	public static function shapes(): iterable {
		foreach (['8.6.3', '9.0.7', '9.1.4', '9.3.0'] as $v) { yield $v => [$v, null, null]; }
		foreach (PollsMutationSurface::methods(true) as $class => $methods) {
			foreach (array_keys($methods) as $method) { yield $class . '::' . $method => ['9.3.0', $class, $method]; }
		}
		yield 'missing invitation' => ['9.3.0', PollsMutationSurface::SHARES, 'sendInvitation'];
		yield 'missing recipient factory' => ['9.3.0', PollsMutationSurface::USER_MAPPER, 'createUserObject'];
		foreach (PollsMutationSurface::FIELDS as $class => $fields) {
			foreach ($fields as $field) { yield $class . '::$' . $field => ['9.3.0', $class, '$' . $field]; }
		}
		yield 'missing native date constructor' => ['9.3.0', 'OCA\\Polls\\Model\\DateTimeImmutable', '__construct'];
		yield 'missing native duration constructor' => ['9.3.0', 'OCA\\Polls\\Model\\DateInterval', '__construct'];
	}
	/** @param string $version Pin. @param string|null $missingClass Class whose method is absent. @param string|null $missingMethod Method to rename in the fixture only. @return void */
	#[DataProvider('shapes')]
	public function testRealLazyProbeAndEveryMissingMember(string $version, ?string $missingClass, ?string $missingMethod): void {
		$records = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/polls/polls-mutation.json'), true, 512, JSON_THROW_ON_ERROR);
		$r = $records[$version]; $files = []; $root = sys_get_temp_dir() . '/mcp-polls-shape-' . bin2hex(random_bytes(6));
		mkdir($root);
		try {
			foreach ($r['shapeSources'] as $path => $source) {
				self::assertSame($r['files'][$path . '.php'], hash('sha256', $source));
				$class = 'OCA\\Polls\\' . str_replace('/', '\\', $path);
				if ($class === $missingClass) {
					if (str_starts_with($missingMethod, '$')) {
						$field = substr($missingMethod, 1);
						$source = preg_replace('/((?:public|protected|private)\\s+(?:[?\\\\\\w|]+\\s+)?\\$)' . preg_quote($field, '/') . '\\b/',
							'${1}missing_' . $field, $source, 1, $count);
					} else {
						$source = preg_replace('/function\\s+' . preg_quote($missingMethod, '/') . '\\s*\\(/', 'function missing_' . $missingMethod . '(', $source, 1, $count);
					}
					self::assertSame(1, $count);
					if ($missingMethod === '__construct') {
						$source = preg_replace_callback('/(function missing___construct\\s*\\()(.*?)(\\)\\s*\\{)/s',
							static fn (array $m): string => $m[1] . preg_replace('/\\b(public|protected|private)\\s+/', '', $m[2]) . $m[3], $source, 1);
					}
				}
				$file = $root . '/' . str_replace('/', '-', $path) . '.php'; file_put_contents($file, $source);
				$files[$class] = $file;
			}
			spl_autoload_register(static function (string $class) use ($files): void { if (isset($files[$class])) { require $files[$class]; } });
			$apps = $this->createMock(IAppManager::class); $apps->method('getAppVersion')->willReturn($version); $apps->method('isEnabledForAnyone')->willReturn(true);
			$major = ['8.6.3' => 32, '9.0.7' => 33, '9.1.4' => 34, '9.3.0' => 35][$version];
			$probe = new PollsMutationProbe($apps, new PollsMutationSurface(), $major);
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($missingClass === null ? self::never() : self::once())->method('warning');
			$registry = new CapabilityRegistry([$probe], $logger);
			$expected = $missingClass === null;
			if ($expected) {
				$probe->supports('polls.native_mutation_api');
				$this->assertDateAdapter($version);
				$this->assertAddOptionNeverVotes();
			}
			self::assertSame($expected, $registry->supports('polls.native_mutation_api'));
			self::assertSame($expected, $registry->supports('polls.native_mutation_api'));
			self::assertFalse($registry->supports('polls.no_such_key'));
		} finally { foreach ($files as $file) { unlink($file); } rmdir($root); }
	}
	/** @param string $version Pin whose original model declarations are loaded. @return void */
	private function assertDateAdapter(string $version): void {
		$service = new class {
			/** Last arguments, without a database or native mutation. */
			public array $args = [];
			/** @param mixed ...$args Native argument record. @return void */
			public function update(mixed ...$args): void { $this->args = $args; }
		};
		$adapter = $version === '8.6.3' ? new \OCA\Mcp\Modules\Polls\Integration\Polls86Adapter() : new \OCA\Mcp\Modules\Polls\Integration\Polls9Adapter();
		foreach ([0, 3600, 86400, 2678400] as $duration) {
			$adapter->updateOption($service, ['option_id' => 1, 'option' => ['timestamp' => 1800000000, 'duration' => $duration]]);
			if ($version === '8.6.3') { self::assertSame([1, 1800000000, '', $duration], $service->args); }
			else {
				self::assertInstanceOf(\OCA\Polls\Model\DateTimeImmutable::class, $service->args[2]);
				self::assertInstanceOf(\OCA\Polls\Model\DateInterval::class, $service->args[3]);
				self::assertSame(1800000000, $service->args[2]->getTimestamp());
				self::assertSame($duration, $service->args[3]->getSeconds($service->args[2]));
			}
		}
	}
	/** @return void The official writer is called once with a disposable value and voteYes=false. */
	private function assertAddOptionNeverVotes(): void {
		$service = new class {
			/** Recorded native-call arguments. */
			public array $args = [];
			/** @param int $id Poll ID. @param object $simple Disposable native value. @param bool $vote Automatic vote flag. @return void */
			public function add(int $id, object $simple, bool $vote): void { $this->args = [$id, $simple, $vote]; }
		};
		$factory = $this->createMock(\OCA\Mcp\Modules\Polls\Integration\PollsNativeFactory::class);
		$factory->method('make')->willReturn($service);
		$snapshot = new \OCA\Mcp\Modules\Polls\Integration\PollsMutationSnapshot($factory);
		$writer = new \OCA\Mcp\Modules\Polls\Integration\PollsMutationWriter($factory, $snapshot,
			new \OCA\Mcp\Modules\Polls\Integration\Polls86Adapter(), new \OCA\Mcp\Modules\Polls\Integration\Polls9Adapter());
		$guarded = 0;
		$writer->write(new \stdClass(), ['tool' => 'polls_add_option', 'poll_id' => 1, 'option' => ['text' => 'New']],
			true, static function () use (&$guarded): void { $guarded++; });
		self::assertSame(1, $guarded); self::assertSame(1, $service->args[0]); self::assertFalse($service->args[2]);
		self::assertInstanceOf(\OCA\Polls\Model\SimpleOption::class, $service->args[1]);
	}
}
