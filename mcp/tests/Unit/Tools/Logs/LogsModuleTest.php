<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use InvalidArgumentException;
use OCA\Mcp\Service\LogsAccess;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Logs\LogAudit;
use OCA\Mcp\Tools\Logs\LogEnvelope;
use OCA\Mcp\Tools\Logs\LogReader;
use OCA\Mcp\Tools\Logs\LogScanner;
use OCA\Mcp\Tools\Logs\LogsModule;
use OCA\Mcp\Tools\RestrictedModule;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Log\ILogFactory;
use OCP\Log\IWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LogsModuleTest extends TestCase {
    /** Fixture in the format of the dalcloud log: JSON lines, ISO 8601 times in America/Sao_Paulo. */
    public const FIXTURE = __DIR__ . '/../../../fixtures/nextcloud-log/dalcloud-sample.jsonl';

    private InMemoryConfig $config;
    private FakeFileLog $log;
    /** @var list<Event> */
    private array $events = [];
    /** Whether the event dispatcher throws, as a failing audit backend would. */
    private bool $dispatcherDown = false;
    /** @var list<array{string, array<string, mixed>, string}> app log lines: message, context, level */
    private array $infos = [];

    protected function setUp(): void {
        $this->config = new InMemoryConfig();
        $this->config->system['logtimezone'] = 'America/Sao_Paulo';
        $this->config->system['loglevel'] = 2;
        $this->log = new FakeFileLog(self::fixture());
    }

    /** @return list<string> the fixture lines, oldest first */
    public static function fixture(): array {
        return array_values(array_filter(explode("\n", (string)file_get_contents(self::FIXTURE)), static fn (string $line): bool => $line !== ''));
    }

    /** @param IWriter|null $writer the writer ILogFactory returns; the fixture log when null */
    private function module(?IWriter $writer = null): LogsModule {
        $config = $this->config->mock($this);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
        $factory = $this->createMock(ILogFactory::class);
        $factory->method('get')->willReturn($writer ?? $this->log);
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
        $access = new LogsAccess($config, $groups, $mapper, new \OCA\Mcp\Tests\Unit\InMemoryLocks());
        return new LogsModule($access, new LogScanner(new LogReader($factory)), new LogAudit($dispatcher, $logger), $config);
    }

    /** @return array{0: string, 1: array<string, mixed>} the text and the structured content of a call */
    private function call(string $tool, array $arguments = [], string $uid = 'root'): array {
        $result = $this->module()->call($tool, $arguments, $uid);
        $this->assertArrayNotHasKey('isError', $result);
        return [$result['content'][0]['text'], $result['structuredContent']];
    }

    public function testTwoReadToolsOfTheLogsModuleBehindTheRoleGate(): void {
        $module = $this->module();
        $this->assertInstanceOf(RestrictedModule::class, $module);
        $this->assertInstanceOf(ToolGuideNotes::class, $module);
        $definitions = array_column($module->definitions(), null, 'name');
        $this->assertSame(['logs_list', 'logs_analyze'], array_keys($definitions));
        foreach ($definitions as $definition) {
            $this->assertSame('logs', $definition['module']);
            $this->assertSame('read', $definition['operation']);
            $this->assertArrayNotHasKey('app', $definition);
            $this->assertArrayNotHasKey('confirm', $definition['inputSchema']['properties']);
            $this->assertMatchesRegularExpression('/untrusted/i', $definition['description']);
        }
        $this->assertArrayHasKey('limit', $definitions['logs_list']['inputSchema']['properties']);
        $this->assertArrayNotHasKey('limit', $definitions['logs_analyze']['inputSchema']['properties']);
        $this->assertTrue($module->permits('root'));
        $this->assertFalse($module->permits('alice'));
        $this->config->system['log_type'] = 'syslog';
        $this->assertFalse($this->module()->permits('root'));
    }

    /** The data comes after a fixed warning, inside an envelope that says it is untrusted, in both forms. */
    public function testEveryAnswerIsAnUntrustedEnvelopeAfterAFixedWarning(): void {
        foreach (['logs_list', 'logs_analyze'] as $tool) {
            [$text, $data] = $this->call($tool);
            $this->assertStringStartsWith(LogEnvelope::NOTICE . "\n\n", $text);
            $this->assertSame($data, json_decode(substr($text, strlen(LogEnvelope::NOTICE) + 2), true));
            $this->assertSame(true, $data['untrusted_log_data']);
            $this->assertSame(LogEnvelope::NOTICE, $data['notice']);
            $this->assertSame($tool, $data['tool']);
            $this->assertSame(2, $data['source']['serverLogLevel']);
            $this->assertStringContainsString('nextcloud.log.1', $data['source']['file']);
            $this->assertSame(LogScanner::SCAN_LIMIT, $data['scan']['scanLimit']);
            $this->assertSame(LogScanner::READ_BUDGET, $data['scan']['readBudget']);
            $this->assertFalse($data['scan']['budgetReached']);
        }
    }

    public function testListedEntriesCarryTheAgreedFieldsRedacted(): void {
        [$text, $data] = $this->call('logs_list', ['limit' => 100]);
        $this->assertSame(count($data['entries']), $data['count']);
        $this->assertSame(1, $data['scan']['malformed']);
        foreach ($data['entries'] as $entry) {
            $this->assertSame(['time', 'level', 'app', 'user', 'method', 'url', 'message', 'reqId', 'userAgent', 'remoteAddr'],
                array_values(array_diff(array_keys($entry), ['exception'])));
            $this->assertContains($entry['level'], ['debug', 'info', 'warning', 'error', 'fatal']);
            $this->assertMatchesRegularExpression('/^(\d+\.\d+\.x\.x|[0-9a-f:]+\/48|--|)$/', $entry['remoteAddr']);
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $entry['message']);
        }
        $this->assertStringNotContainsString('"args"', $text);
        $this->assertStringNotContainsString('S3nh4', $text, 'arguments of a frame never leave the server');
        $this->assertDoesNotMatchRegularExpression('/\b189\.6\.12\.34\b/', $text);
        $withException = array_values(array_filter($data['entries'], static fn (array $entry): bool => isset($entry['exception'])));
        $this->assertNotEmpty($withException);
        $this->assertLessThanOrEqual(10, count($withException[0]['exception']['trace']));
    }

    public function testFiltersPagingAndTheScanAreReported(): void {
        [, $data] = $this->call('logs_list', ['app' => 'webdav', 'min_level' => 3, 'limit' => 2]);
        $this->assertSame(['min_level' => 3, 'app' => 'webdav'], $data['filters']);
        $this->assertCount(2, $data['entries']);
        $this->assertSame(['webdav', 'webdav'], array_column($data['entries'], 'app'));
        $this->assertIsInt($data['scan']['nextOffset']);
        [, $next] = $this->call('logs_list', ['app' => 'webdav', 'min_level' => 3, 'limit' => 2, 'offset' => $data['scan']['nextOffset']]);
        $this->assertNotSame($data['entries'][0]['reqId'], $next['entries'][0]['reqId']);
    }

    public function testTheAnalysisSummarizesTheSameWindow(): void {
        [, $data] = $this->call('logs_analyze', ['min_level' => 3]);
        $summary = $data['summary'];
        $this->assertSame(['matched', 'byLevel', 'byApp', 'byUser', 'signatures', 'distinctSignatures', 'topUrls', 'topUserAgents', 'hourly', 'hourlyUnparsed'], array_keys($summary));
        $this->assertSame($summary['matched'], array_sum($summary['byLevel']));
        $this->assertArrayNotHasKey('warning', $summary['byLevel']);
        $this->assertLessThanOrEqual(20, count($summary['signatures']));
    }

    /** The registry around the module, as Application wires it; root holds the grant, alice is outside the role. */
    private function registry(?IWriter $writer = null): ToolRegistry {
        $policy = InMemoryConfig::policy($this->config->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $policy->setGrant('root', 'logs', 'read', true);
        $policy->setGrant('alice', 'logs', 'read', true);
        return new ToolRegistry([$this->module($writer)], $policy, $this->createMock(IAppManager::class), $this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class));
    }

    /** @return list<array{string, array<string, string>}> message and parameters of each audit event */
    private function audited(): array {
        return array_map(static fn (CriticalActionPerformedEvent $event): array => [$event->getLogMessage(), $event->getParameters()], $this->events);
    }

    /**
     * Every call is recorded through the core's audit event, which admin_audit writes when it is enabled: a read as a
     * read, and a call refused or failed under its own message, so a denial never reads as a log read.
     */
    public function testEveryCallIsAuditedWithItsOutcome(): void {
        $registry = $this->registry();
        $registry->call('logs_list', ['app' => 'webdav', 'limit' => 5], 'root');
        $registry->call('logs_analyze', [], 'root');
        try {
            $registry->call('logs_list', ['contains' => 'RH'], 'alice');
            $this->fail('alice is outside the role');
        } catch (InvalidArgumentException) {
        }
        try {
            $registry->call('logs_list', ['since' => 'ontem'], 'root');
            $this->fail('since is not ISO 8601');
        } catch (InvalidArgumentException) {
        }
        $this->log = new FakeFileLog([]);
        $registry = $this->registry();
        $this->assertArrayNotHasKey('isError', $registry->call('logs_list', [], 'root'));

        $this->assertSame([
            [LogAudit::READ, ['user' => 'root', 'tool' => 'logs_list', 'filters' => '{"app":"webdav","offset":0,"limit":5}']],
            [LogAudit::READ, ['user' => 'root', 'tool' => 'logs_analyze', 'filters' => '{"offset":0}']],
            [LogAudit::REFUSED, ['outcome' => 'denied', 'user' => 'alice', 'tool' => 'logs_list', 'filters' => '{"contains":"RH"}']],
            // Refused by the schema: the arguments as sent, without the defaults.
            [LogAudit::REFUSED, ['outcome' => 'invalid', 'user' => 'root', 'tool' => 'logs_list', 'filters' => '{"since":"ontem"}']],
            [LogAudit::READ, ['user' => 'root', 'tool' => 'logs_list', 'filters' => '{"offset":0,"limit":50}']],
        ], $this->audited());
        $this->assertSame('MCP logs read by %s: tool=%s filters=%s', LogAudit::READ);
        $this->assertSame('MCP logs request %s for %s: tool=%s filters=%s', LogAudit::REFUSED);
        $this->assertSame(['MCP logs read', 'MCP logs read', 'MCP logs request denied', 'MCP logs request invalid', 'MCP logs read'], array_column($this->infos, 0));
        $this->assertSame('denied', $this->infos[2][1]['outcome']);
        $this->assertSame(['info', 'info', 'warning', 'info', 'info'], array_column($this->infos, 2), 'a denial is a warning in the app log');
    }

    /** A log the core cannot read is a failure of the read, audited as such. */
    public function testAReadErrorIsAudited(): void {
        $registry = $this->registry($this->createMock(IWriter::class));

        $this->assertTrue($registry->call('logs_analyze', [], 'root')['isError']);
        $this->assertSame([[LogAudit::REFUSED, ['outcome' => 'read_error', 'user' => 'root', 'tool' => 'logs_analyze', 'filters' => '{"offset":0}']]], $this->audited());
    }

    /** What a refused call recorded is bounded: known filters only, scalars only, each cut, whatever the caller sent. */
    public function testTheAuditKeepsOnlyBoundedKnownFilters(): void {
        $this->module()->audit('logs_list', 'alice', 'denied', ['contains' => str_repeat('x', 500), 'evil' => 'drop me', 'app' => ['nested'], 'offset' => 3]);
        $filters = json_decode($this->audited()[0][1]['filters'], true);
        $this->assertSame(['contains', 'offset'], array_keys($filters));
        $this->assertSame(201, mb_strlen($filters['contains']));
        $this->assertSame(3, $filters['offset']);
    }

    /** The module refuses invalid arguments before reading anything; the registry is what audits the attempt. */
    public function testInvalidArgumentsAreRefusedBeforeAnythingIsRead(): void {
        foreach ([['logs_list', ['since' => 'ontem']], ['logs_analyze', ['contains' => '']], ['logs_delete', []], ['logs_list', ['offset' => 20001]]] as [$tool, $arguments]) {
            try {
                $this->module()->call($tool, $arguments, 'root');
                $this->fail($tool . ' ' . json_encode($arguments) . ' must be refused');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->log->calls);
        $this->assertSame([], $this->events, 'the module itself never audits: the registry does');
    }

    /** A prompt hidden in a file name stays data: no control character, inside the envelope, after the warning. */
    public function testAnInjectionAttemptStaysInertData(): void {
        [$text, $data] = $this->call('logs_list', ['contains' => 'IGNORE']);
        $this->assertCount(1, $data['entries']);
        $this->assertStringNotContainsString("\n<", $data['entries'][0]['message']);
        $this->assertLessThan(strpos($text, 'IGNORE'), strlen(LogEnvelope::NOTICE));
    }

    /** With the audit backend down: the refusals stay what they were, and a read hands no log data out. */
    public function testWithTheAuditDownNoLogDataLeavesAndRefusalsStay(): void {
        $this->dispatcherDown = true;
        $registry = $this->registry();

        $read = $registry->call('logs_list', ['limit' => 5], 'root');
        $this->assertTrue($read['isError']);
        $this->assertArrayNotHasKey('structuredContent', $read);
        $this->assertStringNotContainsString(LogEnvelope::NOTICE, $read['content'][0]['text']);
        try {
            $registry->call('logs_list', [], 'alice');
            $this->fail('denied stays denied');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Unknown tool', $e->getMessage());
        }
        try {
            $registry->call('logs_list', ['since' => 'ontem'], 'root');
            $this->fail('invalid stays invalid');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('Unknown tool', $e->getMessage());
        }
        $failed = $this->registry($this->createMock(IWriter::class))->call('logs_analyze', [], 'root');
        $this->assertSame('This server does not write its log to a file, so there is nothing to read.', $failed['content'][0]['text']);
        $this->assertSame([], $this->events);
    }
}
