<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use InvalidArgumentException;
use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Tools\RestrictedModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Logs\LogAudit;
use OCA\Mcp\Modules\Logs\LogsModule;
use OCA\Mcp\Modules\Logs\Presentation\LogRedactor;
use OCA\Mcp\Modules\Logs\Reading\LogReader;
use OCA\Mcp\Modules\Logs\Reading\LogScanner;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Log\ILogFactory;
use OCP\Log\IWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Observed answers of logs_list and logs_analyze at the move commit, before the redactor, the window and the catalog
 * were split. The same inputs on this tree have to reproduce logs-characterization.json.
 */
final class LogsCharacterizationTest extends TestCase {
	private const SECRET = 'salary.secret';
	private const TOKEN = 'ShareToken9f3a';
	private const FIXTURE = __DIR__ . '/logs-characterization.json';

	private InMemoryConfig $config;
	private \OCA\Mcp\Tests\Unit\InMemoryAppConfig $appConfig;
	/** @var list<Event> */
	private array $events = [];
	/** @var list<array{0: string, 1: array<string, mixed>, 2: string}> */
	private array $infos = [];
	private bool $dispatcherDown = false;
	private bool $hide = false;
	/** @var IWriter|null a writer that is not a file, when the read must fail */
	private ?IWriter $brokenWriter = null;

	protected function setUp(): void {
		$this->appConfig = new \OCA\Mcp\Tests\Unit\InMemoryAppConfig();
		$this->config = new InMemoryConfig();
		$this->config->system['logtimezone'] = 'America/Sao_Paulo';
		$this->config->system['loglevel'] = 2;
	}

	public function testTheSplitPreservesTheCharacterizedAnswers(): void {
		$report = $this->report();
		$this->assertFragilePoints($report);
		$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('RE14B_FREEZE') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}
		$this->assertFileExists(self::FIXTURE);
		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}

	/** @param array<string, mixed> $report */
	private function assertFragilePoints(array $report): void {
		$hidden = json_encode($report['hidden'], JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString(self::SECRET, $hidden);
		$this->assertStringNotContainsString(self::TOKEN, $hidden);
		$this->assertSame('[hidden]', $report['hidden']['list']['entries'][0]['message']);
		$this->assertSame('[hidden]', $report['hidden']['list']['entries'][0]['exception']['previous']['message']);
		$this->assertStringNotContainsString(self::TOKEN, json_encode($report['open']['analyze']['summary']['topUrls'], JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString('203.0.113.9', json_encode($report['open']['list_one']['entries'][0]['exception'], JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString(self::SECRET, json_encode($report['open']['list_one']['entries'][0]['exception']['trace'], JSON_THROW_ON_ERROR));
		$this->assertNotSame([], $report['audit']);
		$this->assertSame('[hidden]', $report['audit'][0]['filters']['contains']);
		$this->assertSame('error', $report['open']['list_one']['entries'][0]['level']);
		$this->assertSame('fatal', $report['open']['levels']['fatal']);
		$this->assertSame('warning', $report['open']['levels']['share']);
		$this->assertSame('info', $report['open']['levels']['info']);
		$this->assertSame('debug', $report['open']['levels']['debug']);
		$this->assertSame(1, $report['open']['list_one']['count']);
		$this->assertSame('secret', $report['open']['list_one']['entries'][0]['reqId']);
		$this->assertSame(5, $report['open']['list_all']['count']);
		$this->assertSame(5, $report['open']['analyze_with_limit']['summary']['matched']);
		$this->assertTrue($report['errors']['audit_down']['isError']);
		$this->assertStringNotContainsString(self::SECRET, $report['errors']['audit_down']['text']);
		$this->assertStringNotContainsString(self::TOKEN, $report['errors']['audit_down']['text']);
	}

	/** @return array<string, mixed> */
	private function report(): array {
		$this->hide = false;
		$one = $this->call('logs_list', ['limit' => 1]);
		$all = $this->call('logs_list');
		$analyze = $this->call('logs_analyze');
		$analyzeLimited = $this->call('logs_analyze', ['limit' => 1]);
		$open = [
			'list_one' => $one,
			'list_all' => ['count' => $all['count'], 'levels' => array_column($all['entries'], 'level', 'reqId')],
			'levels' => array_column($all['entries'], 'level', 'reqId'),
			'analyze' => $analyze,
			'analyze_with_limit' => $analyzeLimited,
			'refusals' => [
				'unknown' => $this->registryRefusal('logs_delete', []),
				'limit_zero' => $this->refusal('logs_list', ['limit' => 0]),
				'limit_over' => $this->refusal('logs_list', ['limit' => 101]),
			],
		];

		$this->hide = true;
		$hiddenList = $this->call('logs_list', ['limit' => 1]);
		$hiddenAnalyze = $this->call('logs_analyze');
		$hidden = [
			'list' => $hiddenList,
			'analyze' => $hiddenAnalyze,
			'contains' => $this->refusal('logs_list', ['contains' => self::SECRET]),
		];

		$this->hide = false;
		$this->events = [];
		$this->infos = [];
		$this->module()->audit('logs_list', 'alice', RestrictedModule::DENIED, [
			'contains' => self::SECRET,
			'user' => str_repeat('y', 250),
			'offset' => 3,
			'limit' => 1,
			'min_level' => 2,
			'evil' => 'drop me',
			'app' => ['nested'],
		]);
		$this->module()->audit(str_repeat('t', 80), 'root', RestrictedModule::SUCCESS, ['contains' => 7]);
		$audit = $this->audited();

		$this->dispatcherDown = true;
		$this->hide = false;
		$down = $this->registry()->call('logs_list', ['limit' => 1], 'root');
		$this->brokenWriter = $this->createMock(IWriter::class);
		$this->dispatcherDown = false;
		$this->events = [];
		$failed = $this->registry()->call('logs_analyze', [], 'root');

		$chain = LogRedactor::exception(json_decode(json_encode($this->exceptionRow()), false));

		return [
			'open' => $open,
			'hidden' => $hidden,
			'audit' => $audit,
			'redactor' => [
				'text' => LogRedactor::text('token ' . self::TOKEN . ' secret ' . self::SECRET . ' from 203.0.113.45', 2000),
				'exception' => $chain,
				'ip' => LogRedactor::ip('203.0.113.45'),
			],
			'errors' => [
				'audit_down' => ['isError' => $down['isError'] ?? false, 'text' => $down['content'][0]['text']],
				'read_error' => [
					'isError' => $failed['isError'] ?? false,
					'text' => $failed['content'][0]['text'],
					'audit' => $this->audited(),
				],
			],
		];
	}

	/** @return array<string, mixed> structured content of one call */
	private function call(string $tool, array $arguments = []): array {
		$result = $this->module()->call($tool, $arguments, 'root');
		$this->assertArrayNotHasKey('isError', $result);

		return $result['structuredContent'];
	}

	/** @return array{class: string, message: string} */
	private function refusal(string $tool, array $arguments): array {
		try {
			$this->module()->call($tool, $arguments, 'root');
		} catch (InvalidArgumentException $e) {
			return ['class' => $e::class, 'message' => $e->getMessage()];
		}
		$this->fail($tool . ' was accepted');
	}

	/**
	 * The registry answers a name that no module publishes. The module is not asked.
	 *
	 * @return array{class: string, message: string}
	 */
	private function registryRefusal(string $tool, array $arguments): array {
		try {
			$this->registry()->call($tool, $arguments, 'root');
		} catch (InvalidArgumentException $e) {
			return ['class' => $e::class, 'message' => $e->getMessage()];
		}
		$this->fail($tool . ' was accepted');
	}

	private function module(): LogsModule {
		$config = $this->config->mock($this);
		if ($this->hide) {
			$config->setAppValue('mcp', VisibilityGuard::CONFIG_KEY, '["42"]');
		} else {
			$config->deleteAppValue('mcp', VisibilityGuard::CONFIG_KEY);
		}
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getBackends')->willReturn([new \OCA\Mcp\Tests\Unit\ArrayGroupBackend(static fn (): array => ['admin' => ['root']])]);
		$reader = FileLogFixture::reader($this, $this->brokenWriter ?? new FakeFileLog($this->encodedLines()));
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			if ($this->dispatcherDown) {
				throw new \RuntimeException('audit backend down');
			}
			$this->events[] = $event;
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->infos[] = [$message, $context, $level];
			});
		}
		$db = new \OCA\Mcp\Tests\Unit\SqliteDatabase($this, [new \OCA\Mcp\Migration\Version001002Date20261008000000()]);
		$mapper = new \OCA\Mcp\Db\LogsGroupsMapper($db->connection(), (new \OCA\Mcp\Tests\Unit\InMemoryAppConfig())->worker($this));
		$access = InMemoryConfig::logsAccess($this, $config, $groups, $mapper, new \OCA\Mcp\Tests\Unit\InMemoryLocks());
		$guard = new VisibilityGuard($config, $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class));
		$fileNames = new LogFileNames($this->appConfig->worker($this), $guard);
		$scanner = new LogScanner($reader);
		$audit = new LogAudit($dispatcher, $logger);
		if ((new \ReflectionMethod(LogsModule::class, '__construct'))->getNumberOfParameters() === 5) {
			return new LogsModule($access, $scanner, $audit, $config, $fileNames);
		}

		return LogsTestModule::build($access, $scanner, $audit, $config, $fileNames);
	}

	private function registry(): ToolRegistry {
		$policy = InMemoryConfig::policy($this->config->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore());
		$policy->setGrant('root', 'logs', 'read', true);

		return new ToolRegistry(
			[$this->module()],
			$policy,
			$this->createMock(IAppManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	/** @return list<array{message: string, parameters: array<string, mixed>, filters: array<string, mixed>}> */
	private function audited(): array {
		$rows = [];
		foreach ($this->events as $event) {
			$this->assertInstanceOf(CriticalActionPerformedEvent::class, $event);
			$parameters = $event->getParameters();
			$rows[] = [
				'message' => $event->getLogMessage(),
				'parameters' => $parameters,
				'filters' => json_decode($parameters['filters'], true, 512, JSON_THROW_ON_ERROR),
			];
		}

		return $rows;
	}

	/** @return list<string> */
	private function encodedLines(): array {
		$lines = [];
		foreach ($this->rows() as $row) {
			$lines[] = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		}

		return $lines;
	}

	/** @return list<array<string, mixed>> oldest first; the secret entry is the newest */
	private function rows(): array {
		$base = [
			'time' => '2026-10-07T08:12:03-03:00',
			'remoteAddr' => '203.0.113.45',
			'user' => 'alice',
			'app' => 'webdav',
			'method' => 'GET',
			'userAgent' => 'curl/8.0 from 10.1.2.3',
		];

		return [
			$base + ['reqId' => 'debug', 'level' => 0, 'message' => 'debug note', 'url' => '/index.php/apps/files/'],
			$base + ['reqId' => 'info', 'level' => 1, 'message' => 'info from 198.51.100.7', 'url' => '/index.php/apps/files/'],
			$base + ['reqId' => 'fatal', 'level' => 4, 'message' => 'fatal stop', 'url' => '/public.php/dav/files/' . self::TOKEN . '/file.txt'],
			$base + ['reqId' => 'share', 'level' => 2, 'message' => 'public share', 'url' => '/s/' . self::TOKEN . '/download'],
			$base + ['reqId' => 'secret', 'level' => 3, 'message' => 'Cannot open ' . self::SECRET, 'url' => '/remote.php/dav/files/alice/' . self::SECRET, 'exception' => $this->exceptionRow()],
		];
	}

	/** @return array<string, mixed> */
	private function exceptionRow(): array {
		return [
			'Exception' => 'OCP\\Files\\NotFoundException',
			'Message' => "Not found " . self::SECRET . " from 203.0.113.9\n",
			'Code' => 404,
			'File' => '/var/www/nextcloud/lib/private/Files/View.php',
			'Line' => 1500,
			'Trace' => [[
				'file' => '/var/www/nextcloud/lib/private/Files/View.php',
				'line' => 12,
				'function' => 'fopen',
				'class' => 'OC\\Files\\View',
				'type' => '->',
				'args' => [self::SECRET, self::TOKEN],
			]],
			'Previous' => [
				'Exception' => 'RuntimeException',
				'Message' => 'inner ' . self::SECRET . ' from 203.0.113.9',
				'Code' => 0,
				'File' => '/x.php',
				'Line' => 2,
				'Trace' => [['function' => 'open', 'args' => [self::TOKEN]]],
				'Previous' => [
					'Exception' => 'LogicException',
					'Message' => 'third ' . self::SECRET,
					'Code' => 0,
					'Trace' => [],
					'Previous' => [
						'Exception' => 'OverflowException',
						'Message' => 'dropped ' . self::SECRET,
						'Code' => 0,
						'Trace' => [],
					],
				],
			],
		];
	}
}
