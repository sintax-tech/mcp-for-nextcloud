<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tests\Support\Logs\FakeFileLog;
use OCA\Mcp\Modules\Logs\LogsModule;
use OCA\Mcp\Modules\Logs\Reading\LogReader;
use OCA\Mcp\Modules\Logs\Reading\LogScanner;
use OCA\Mcp\Modules\Logs\Audit\LogAudit;
use OCA\Mcp\Modules\Logs\Presentation\LogEnvelope;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Guide\ToolGuide;
use OCA\Mcp\Mcp\Tools\RestrictedModule;
use OCP\Log\{ILogFactory, IWriter, IFileBased};
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LogsMigrationCompatibilityTest extends TestCase {
    private InMemoryConfig $config;
    private VisibilityGuard $guard;
    private LogFileNames $privacy;
    private GrantPolicy $policy;
    private FakeFileLog $log;
    private bool $allowed = true;
    private ?\Throwable $roleFailure = null;
    private ?\Throwable $factoryFailure = null;
    private bool $dispatcherDown = false;
    private bool $loggerDown = false;
    private array $events = [];
    private array $records = [];
    private array $order = [];

    protected function tearDown(): void { Translator::reset(); }

    private function module(?IWriter $writer = null): LogsModule {
        $config = $this->config->mock($this);
        $groups = $this->createMock(\OCP\IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(function (): bool {
            if ($this->roleFailure) { throw $this->roleFailure; }
            return $this->allowed;
        });
        $db = new \OCA\Mcp\Tests\Unit\SqliteDatabase($this, [new \OCA\Mcp\Migration\Version001002Date20261008000000()]);
        $mapper = new \OCA\Mcp\Db\LogsGroupsMapper($db->connection(), (new InMemoryAppConfig())->worker($this));
        $groups->method('getBackends')->willReturn([new \OCA\Mcp\Tests\Unit\ArrayGroupBackend(function (): array { if ($this->roleFailure) { throw $this->roleFailure; } return ['admin' => $this->allowed ? ['root'] : []]; })]);
        $access = \OCA\Mcp\Tests\Unit\InMemoryConfig::logsAccess($this, $config, $groups, $mapper, new \OCA\Mcp\Tests\Unit\InMemoryLocks());
        $factory = $this->createMock(ILogFactory::class);
        $factory->method('get')->with('file')->willReturnCallback(function () use ($writer): IWriter {
            if ($this->factoryFailure) { throw $this->factoryFailure; }
            return $writer ?? $this->log;
        });
        $dispatcher = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(function ($event): void {
            $this->order[] = 'event';
            if ($this->dispatcherDown) { throw new \RuntimeException('private audit failure'); }
            $this->events[] = [$event->getLogMessage(), $event->getParameters()];
        });
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['info', 'warning'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message, $context) use ($level): void {
                $this->order[] = 'logger';
                if ($this->loggerDown) { throw new \RuntimeException('private logger failure'); }
                $this->records[] = [$message, $context, $level];
            });
        }
        $query = new \OCA\Mcp\Modules\Logs\Reading\LogQuery(new LogScanner(new LogReader($factory)), $config, $this->privacy);
        $envelope = new \OCA\Mcp\Modules\Logs\Presentation\LogEnvelope($config);
        return new LogsModule([
            'logs_list' => static fn () => new \OCA\Mcp\Modules\Logs\Tools\ListLogs(new \OCA\Mcp\Modules\Logs\Listing\ListEntries($query, $envelope)),
            'logs_analyze' => static fn () => new \OCA\Mcp\Modules\Logs\Tools\AnalyzeLogs(new \OCA\Mcp\Modules\Logs\Analysis\AnalyzeEntries($query, $envelope)),
        ], static fn () => $access, static fn () => new LogAudit($dispatcher, $logger));
    }

    private function boot(?IWriter $writer = null): array {
        Translator::reset();
        $this->allowed = true;
        $this->roleFailure = $this->factoryFailure = null;
        $this->dispatcherDown = $this->loggerDown = false;
        $this->events = $this->records = $this->order = [];
        $this->config = new InMemoryConfig();
        $this->config->system['logtimezone'] = 'America/Sao_Paulo';
        $this->config->system['loglevel'] = 2;
        $config = $this->config->mock($this);
        $this->guard = new VisibilityGuard($config, $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class));
        $this->privacy = new LogFileNames((new InMemoryAppConfig())->worker($this), $this->guard);
        $this->policy = InMemoryConfig::policy($config, new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $this->policy->setGrant('root', 'logs', 'read', true);
        $lines = file(dirname(__DIR__, 2).'/fixtures/nextcloud-log/sample.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->log = new FakeFileLog($lines);
        $module = $this->module($writer);
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->expects(self::never())->method('isEnabledForUser');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willThrowException(new \RuntimeException('contingency logger down'));
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->policy, $apps, $this->createMock(\OCP\IUserManager::class));
        return [$module, new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $logger))];
    }

    /** Capture once before production extraction; tests only compare the stored bytes. */
    public function capture(): array {
        [$module, $registry] = $this->boot();
        $published = $registry->definitions('root');
        $definitions = ['raw' => $module->definitions(), 'published' => $published,
            'guide' => (new ToolGuide([$module]))->result($published, ['module' => 'logs']),
            'moduleOrder' => array_map(static fn ($class): string => substr($class, strrpos($class, '\\') + 1), Application::MODULES)];
        $results = [];
        foreach ([false, true] as $hidden) {
            $this->guard->setHiddenTagIds($hidden ? ['7'] : []);
            foreach (['logs_list', 'logs_analyze'] as $tool) {
                foreach (['all' => [], 'filtered' => ['app' => 'webdav', 'min_level' => 3], 'empty' => ['app' => 'absent']] as $case => $args) {
                    $results[$tool.'_'.($hidden ? 'hidden_' : 'visible_').$case] = $registry->call($tool, $args, 'root');
                }
            }
        }
        $this->guard->setHiddenTagIds([]);
        $results['page'] = $registry->call('logs_list', ['limit' => 2, 'offset' => 2], 'root');
        foreach (['en', 'pt_BR', 'es'] as $locale) {
            Translator::use(new JsonL10n($locale));
            $this->factoryFailure = new \RuntimeException('/synthetic/private/log');
            $results['read_error_'.$locale] = $registry->call('logs_list', [], 'root');
            $this->factoryFailure = null;
            $nonFile = $this->module($this->createMock(IWriter::class));
            try { $nonFile->call('logs_list', [], 'root'); self::fail('Expected non-file failure'); }
            catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) { $results['non_file_'.$locale] = \OCA\Mcp\Mcp\Tools\Results\ToolResult::error($e->getMessage()); }
        }
        Translator::reset();
        [$module, $registry] = $this->boot();
        $registry->call('logs_list', ['limit' => 2], 'root');
        $registry->call('logs_analyze', ['app' => 'absent'], 'root');
        $this->allowed = false;
        $this->refused(fn () => $registry->call('logs_list', ['contains' => 'synthetic secret'], 'root'));
        $this->allowed = true;
        $this->refused(fn () => $registry->call('logs_list', ['limit' => '2'], 'root'));
        $this->factoryFailure = new \RuntimeException('private');
        $registry->call('logs_analyze', [], 'root');
        return ['definitions' => $definitions, 'results' => $results, 'audit' => ['events' => $this->events, 'records' => $this->records]];
    }

    private function refused(\Closure $call): \InvalidArgumentException {
        try { $call(); } catch (\InvalidArgumentException $e) { self::addToAssertionCount(1); return $e; }
        self::fail('Expected refusal');
    }

    public function testFrozenLogsPublicationResultsAndAudit(): void {
        foreach ($this->capture() as $name => $data) {
            self::assertSame(file_get_contents(dirname(__DIR__, 2).'/Support/Logs/Compatibility/'.$name.'.json'),
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function testLogsRoleGrantAndPrivacyChangesAreObservedBetweenCalls(): void {
        [$module, $registry] = $this->boot();
        self::assertCount(2, $registry->definitions('root'));
        $this->allowed = false;
        self::assertSame([], $registry->definitions('root'));
        $this->refused(fn () => $registry->call('logs_list', [], 'root'));
        $this->allowed = true;
        $this->policy->setGrant('root', 'logs', 'read', false);
        $this->refused(fn () => $registry->call('logs_list', [], 'root'));
        self::assertSame([], $this->log->calls);
        $this->policy->setGrant('root', 'logs', 'read', true);
        $this->guard->setHiddenTagIds(['7']);
        $this->refused(fn () => $registry->call('logs_list', ['contains' => 'secret'], 'root'));
        self::assertSame([], $this->log->calls);
        self::assertTrue($registry->call('logs_list', [], 'root')['structuredContent']['source']['messagesHidden']);
        $this->privacy->setEnabled(false);
        self::assertFalse($registry->call('logs_list', [], 'root')['structuredContent']['source']['messagesHidden']);
        $this->privacy->setEnabled(true);
        $this->guard->setHiddenTagIds([]);
        self::assertFalse($registry->call('logs_list', [], 'root')['structuredContent']['source']['messagesHidden']);
    }

    public function testLogsAuditFailurePreservesEachOutcome(): void {
        foreach (['dispatcherDown', 'loggerDown'] as $down) {
            [$module, $registry] = $this->boot();
            $this->{$down} = true;
            $result = $registry->call('logs_list', [], 'root');
            self::assertTrue($result['isError']);
            self::assertArrayNotHasKey('structuredContent', $result);
            self::assertStringNotContainsString(LogEnvelope::NOTICE, $result['content'][0]['text']);
            self::assertSame($down === 'dispatcherDown' ? ['event'] : ['event', 'logger'], $this->order);
            self::assertCount($down === 'dispatcherDown' ? 0 : 1, $this->events);
            $this->allowed = false;
            self::assertSame('Unknown tool', $this->refused(fn () => $registry->call('logs_list', [], 'root'))->getMessage());
            $this->allowed = true;
            $this->refused(fn () => $registry->call('logs_list', ['limit' => '2'], 'root'));
            $this->factoryFailure = new \RuntimeException('/private/path');
            self::assertSame('The server log could not be read.', $registry->call('logs_list', [], 'root')['content'][0]['text']);
        }
    }

    public function testLogsDirectValidationOrderAndFreshTimeSettings(): void {
        [$module, $registry] = $this->boot();
        $this->guard->setHiddenTagIds(['7']);
        self::assertStringStartsWith('contains is not available', $this->refused(fn () => $module->call('logs_list', ['contains' => '', 'since' => 'bad', 'limit' => 0, 'offset' => -1], 'root'))->getMessage());
        self::assertSame([], $this->log->calls);
        $module->call('logs_list', ['contains' => null, 'limit' => '2'], 'root');
        self::assertSame([[2, 0]], $this->log->calls);
        $this->refused(fn () => $registry->call('logs_list', ['limit' => '2'], 'root'));
        $this->refused(fn () => $registry->call('logs_analyze', ['limit' => 2], 'root'));
        $this->guard->setHiddenTagIds([]);
        $this->refused(fn () => $module->call('logs_list', ['offset' => 20001], 'root'));
        $exhausted = $module->call('logs_list', ['offset' => 20000], 'root')['structuredContent'];
        self::assertSame(0, $exhausted['scan']['scanned']);
        self::assertTrue($exhausted['scan']['budgetReached']);
        self::assertNull($exhausted['scan']['nextOffset']);
        self::assertSame([[2, 0]], $this->log->calls);
        $this->log->lines = [json_encode(['level' => 2, 'time' => '17.10.2026 12:00:00', 'message' => 'sample'])];
        $before = $module->call('logs_analyze', [], 'root')['structuredContent'];
        self::assertSame(1, $before['summary']['hourlyUnparsed']);
        $this->config->system['logdateformat'] = 'd.m.Y H:i:s';
        $this->config->system['logtimezone'] = 'UTC';
        $this->config->system['loglevel'] = 4;
        $after = $module->call('logs_analyze', [], 'root')['structuredContent'];
        self::assertSame([['hour' => '2026-10-17T12:00+00:00', 'count' => 1]], $after['summary']['hourly']);
        self::assertSame(4, $after['source']['serverLogLevel']);
        $writer = $this->createMockForIntersectionOfInterfaces([IWriter::class, IFileBased::class]);
        $writer->method('getEntries')->willReturnCallback(function (): array {
            $this->config->system['logdateformat'] = 'd.m.Y H:i:s';
            $this->config->system['logtimezone'] = 'UTC';
            return [(object)['level' => 2, 'time' => '17.10.2026 12:00:00']];
        });
        [$module] = $this->boot($writer);
        self::assertSame([['hour' => '2026-10-17T12:00+00:00', 'count' => 1]],
            $module->call('logs_analyze', [], 'root')['structuredContent']['summary']['hourly'],
            'Analysis reads time settings again after the native scan');
    }

    public function testLogsNativeFailuresAndRoleExceptionsKeepTheirBoundary(): void {
        foreach ([new \RuntimeException('/private/path'), new \Error('/private/path')] as $failure) {
            foreach (['factory', 'writer'] as $stage) {
                $writer = $this->createMockForIntersectionOfInterfaces([IWriter::class, IFileBased::class]);
                $writer->method('getEntries')->willThrowException($failure);
                [$module, $registry] = $this->boot($writer);
                if ($stage === 'factory') { $this->factoryFailure = $failure; }
                self::assertSame('The server log could not be read.', $registry->call('logs_list', [], 'root')['content'][0]['text']);
                self::assertSame('read_error', $this->events[0][1]['outcome']);
            }
        }
        [$module, $registry] = $this->boot();
        $failure = new \RuntimeException('role unavailable');
        $this->roleFailure = $failure;
        try { $registry->call('logs_list', [], 'root'); self::fail('Expected role failure'); }
        catch (\OCA\Mcp\Access\Policy\PolicyUnavailable $e) { self::assertSame($failure, $e->getPrevious()); }
        $this->refused(fn () => $registry->call('logs_missing', [], 'root'));
        $this->refused(fn () => $module->call('logs_missing', [], 'root'));
        self::assertSame([], $this->events);
        self::assertSame([], $this->log->calls);
    }

    public function testLogsTextEncodingDoesNotNormalizeStructuredFilters(): void {
        [$module] = $this->boot();
        $result = $module->call('logs_list', ['app' => "\xFF"], 'root');
        self::assertSame("\xFF", $result['structuredContent']['filters']['app']);
        $text = substr($result['content'][0]['text'], strlen(LogEnvelope::NOTICE) + 2);
        self::assertSame("\u{FFFD}", json_decode($text, true, 512, JSON_THROW_ON_ERROR)['filters']['app']);
    }
}
