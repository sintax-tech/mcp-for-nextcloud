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
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Log\ILogFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LogsModuleTest extends TestCase {
    /** Fixture in the format of the dalcloud log: JSON lines, ISO 8601 times in America/Sao_Paulo. */
    public const FIXTURE = __DIR__ . '/../../../fixtures/nextcloud-log/dalcloud-sample.jsonl';

    private InMemoryConfig $config;
    private FakeFileLog $log;
    /** @var list<Event> */
    private array $events = [];
    /** @var list<array{string, array<string, mixed>}> */
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

    private function module(): LogsModule {
        $config = $this->config->mock($this);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
        $factory = $this->createMock(ILogFactory::class);
        $factory->method('get')->willReturn($this->log);
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
            $this->events[] = $event;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $message, array $context = []): void {
            $this->infos[] = [$message, $context];
        });
        return new LogsModule(new LogsAccess($config, $groups), new LogScanner(new LogReader($factory)), new LogAudit($dispatcher, $logger), $config);
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

    /** Every call is recorded through the core's audit event, which admin_audit writes when it is enabled. */
    public function testEveryCallIsAudited(): void {
        $this->call('logs_list', ['app' => 'webdav', 'limit' => 5]);
        $this->call('logs_analyze');

        $this->assertCount(2, $this->events);
        $this->assertInstanceOf(CriticalActionPerformedEvent::class, $this->events[0]);
        $this->assertSame('MCP logs read by %s: tool=%s filters=%s', $this->events[0]->getLogMessage());
        $this->assertSame(['user' => 'root', 'tool' => 'logs_list', 'filters' => '{"app":"webdav","offset":0,"limit":5}'], $this->events[0]->getParameters());
        $this->assertSame(['user' => 'root', 'tool' => 'logs_analyze', 'filters' => '{"offset":0}'], $this->events[1]->getParameters());
        $this->assertSame('MCP logs read', $this->infos[0][0]);
        $this->assertSame(['app' => 'mcp', 'user' => 'root', 'tool' => 'logs_list', 'filters' => '{"app":"webdav","offset":0,"limit":5}'], $this->infos[0][1]);
    }

    public function testInvalidArgumentsAreRefusedBeforeAnythingIsReadOrAudited(): void {
        foreach ([['logs_list', ['since' => 'ontem']], ['logs_analyze', ['contains' => '']], ['logs_delete', []]] as [$tool, $arguments]) {
            try {
                $this->module()->call($tool, $arguments, 'root');
                $this->fail($tool . ' ' . json_encode($arguments) . ' must be refused');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->log->calls);
        $this->assertSame([], $this->events);
    }

    /** A prompt hidden in a file name stays data: no control character, inside the envelope, after the warning. */
    public function testAnInjectionAttemptStaysInertData(): void {
        [$text, $data] = $this->call('logs_list', ['contains' => 'IGNORE']);
        $this->assertCount(1, $data['entries']);
        $this->assertStringNotContainsString("\n<", $data['entries'][0]['message']);
        $this->assertLessThan(strpos($text, 'IGNORE'), strlen(LogEnvelope::NOTICE));
    }
}
