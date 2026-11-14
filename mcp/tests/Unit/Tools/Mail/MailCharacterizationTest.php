<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** Fixed inputs and observed results of the Mail module before its catalog split. */
final class MailCharacterizationTest extends TestCase {
	#[RunInSeparateProcess]
	public function testTheSplitPreservesMailCallsAndEffects(): void {
		// The old composition reads the clock once per message call, before any read or refusal.
		eval('namespace OCA\\Mcp\\Modules\\Mail\\Reading; function time(): int { return 1790000100; }');
		$report = ['native_values' => $this->nativeValues()];
		foreach (['mail_list_accounts' => [], 'mail_list_mailboxes' => ['account_id' => 1],
			'mail_list_messages' => ['mailbox_id' => 5, 'limit' => 1],
			'mail_search_messages' => ['subject' => 'Résumé'],
			'mail_read_message' => ['message_id' => 50], 'mail_read_thread' => ['message_id' => 50],
			'mail_read_attachment' => ['message_id' => 50, 'attachment_id' => '2']] as $tool => $arguments) {
			[$module, $app] = $this->scene();
			$report['read'][$tool] = $this->observe($app, fn () => $module->call($tool, $arguments, 'alice'));
		}
		$writes = ['mail_set_flags' => ['message_id' => 50, 'seen' => true, 'flagged' => true],
			'mail_move_message' => ['message_id' => 50, 'mailbox_id' => 8],
			'mail_create_draft' => ['account_id' => 1, 'to' => ['bob@example.com'], 'bcc' => ['copy@example.com'], 'subject' => 'Résumé', 'body' => 'Olá'],
			'mail_send_message' => ['account_id' => 1, 'to' => ['bob@example.com'], 'bcc' => ['copy@example.com'], 'subject' => 'Résumé', 'body' => 'Olá']];
		foreach ($writes as $tool => $arguments) {
			[$module, $app] = $this->scene();
			$report['write'][$tool]['preview'] = $this->observe($app, fn () => $module->preview($tool, $arguments, 'alice'));
			$plan = $module->preview($tool, $arguments, 'alice');
			$report['write'][$tool]['render'] = $module->renderPlan($tool, $plan);
			$report['write'][$tool]['missing_state'] = $this->observe($app, fn () => $module->call($tool, $arguments, 'alice'));
			$report['write'][$tool]['bad_state'] = $this->observe($app, fn () => $module->call($tool, $arguments + [PlanState::ARGUMENT => 'bad'], 'alice'));
			$report['write'][$tool]['execute'] = $this->observe($app, fn () => $module->call($tool, $arguments + [PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice'));
		}
		foreach (['foreign' => static function ($app): void {}, 'disabled' => static function ($app): void { $app->enabled = false; },
			'generation' => static function ($app): void { $app->uidValidity['INBOX'] = '9'; },
			'server' => static function ($app): void { $app->serverFails = true; }] as $label => $change) {
			foreach (['mail_read_message', 'mail_read_attachment'] as $tool) {
				[$module, $app] = $this->scene(); $change($app);
				$args = ['message_id' => $label === 'foreign' ? 999 : 50, 'attachment_id' => '2'];
				$report['refusal'][$label][$tool] = $this->observe($app, fn () => $module->call($tool, $args, 'alice'));
			}
		}
		[$module] = $this->scene();
		$mailboxes = (new FakeMailApp())->withAccount(1, 'alice', 'Work', 'alice@example.com')->withMailbox(1, ['id' => 5, 'name' => 'INBOX']);
		$report['folder_success'] = (new \OCA\Mcp\Modules\Mail\Reading\ListMailboxesHandler(new \OCA\Mcp\Modules\Mail\Integration\MailGateway($mailboxes), FakeMailLinks::create($this)))->handle(['account_id' => 1], 'alice');
		$report['definitions'] = array_map(static function (array $definition): array { unset($definition['requires']); ksort($definition); return $definition; }, $module->definitions());
		$report['guide'] = $module->guideNotes();
		// Observe the real registry too: the legacy version gate and the catalog probe both allow PEEK.
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		foreach (['read', 'messages', 'attachments', 'flags', 'move', 'draft', 'send', 'save'] as $grant) { $policy->setGrant('alice', 'mail', $grant, true); }
		$apps = $this->createStub(\OCP\App\IAppManager::class);
		$apps->method('getAppVersion')->willReturn('5.12.5');
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createStub(\OCP\IUserManager::class);
		$users->method('get')->willReturn($this->createStub(\OCP\IUser::class));
		$capabilities = class_exists(MailTestModule::class) ? MailTestModule::capabilities($apps) : null;
		$registry = new \OCA\Mcp\Mcp\Tools\ToolRegistry([$module], $policy, $apps, $users, new \Psr\Log\NullLogger(), null, $capabilities);
		$report['peek_enabled_tools'] = array_values(array_filter($registry->list('alice'), static fn (array $tool): bool => str_starts_with($tool['name'], 'mail_')));
		self::assertSame(array_column($report['definitions'], 'name'), array_column($report['peek_enabled_tools'], 'name'));
		self::assertSame('mail_read_attachment', $report['peek_enabled_tools'][6]['name']);
		self::assertSame('mail_set_flags', $report['peek_enabled_tools'][7]['name']);

		foreach ($report['write'] as $write) {
			self::assertSame([], $write['preview']['outbox']); self::assertSame([], $write['preview']['drafts']); self::assertSame([], $write['preview']['moved']);
			self::assertArrayNotHasKey('exception', $write['execute']);
		}
		self::assertCount(1, $report['write']['mail_send_message']['execute']['outbox']);
		self::assertCount(1, $report['write']['mail_create_draft']['execute']['drafts']);
		self::assertCount(1, $report['write']['mail_move_message']['execute']['moved']);
		self::assertSame('OCA\\Mail\\Service\\Search\\Flag', $report['native_values']['enabled']['flag_on']['value']['class']);
		$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
		$fixture = __DIR__ . '/mail-characterization.json';
		if (getenv('RE25_FREEZE') === '1') { file_put_contents($fixture, $json); }
		self::assertSame(file_get_contents($fixture), $json);
	}

	private function nativeValues(): array {
		eval('namespace OCA\\Mail; class AddressList { public static function parse(string $value): array { return [$value]; } }');
		eval('namespace OCA\\Mail\\Model; class NewMessageData { public array $arguments; public function __construct(...$arguments) { $this->arguments = $arguments; } }');
		eval('namespace OCA\\Mail\\Db; class LocalMessage { public const TYPE_OUTGOING = 4; public const STATUS_PROCESSED = 12; public array $fields = []; public function __call($name, $arguments) { $this->fields[$name] = $arguments; } }');
		eval('namespace OCA\\Mail\\Service\\Search; class SearchQuery {} class Flag { public const SEEN = 1; public function __construct(public string $kind, public int $value) {} public static function is($value): self { return new self("is", $value); } public static function not($value): self { return new self("not", $value); } }');
		$services = new class($this->createStub(\OCP\App\IAppManager::class), $this->createStub(\Psr\Container\ContainerInterface::class), $this->createStub(\OCP\IUserManager::class)) extends \OCA\Mcp\Modules\Mail\Integration\MailServices {
			public bool $enabled = true;
			public array $checks = [];
			public function isEnabledFor(string $userId): bool { $this->checks[] = $userId; return $this->enabled; }
		};
		$report = [];
		foreach ([true, false] as $enabled) {
			$services->enabled = $enabled;
			foreach (['draft' => fn () => $services->newMessageData('alice', (object)['id' => 1], 'to', 'cc', 'bcc', 'Résumé', 'Olá'),
				'outgoing' => fn () => $services->newOutgoingMessage('alice', 1, 'Résumé', 'Olá'),
				'status' => fn () => $services->localMessageStatuses('alice', ['STATUS_PROCESSED', 'MISSING']),
				'query' => fn () => $services->newSearchQuery('alice'),
				'flag_on' => fn () => $services->flag('alice', 'SEEN', true),
				'flag_off' => fn () => $services->flag('alice', 'SEEN', false),
				'flag_unknown' => fn () => $services->flag('alice', 'MISSING', false)] as $name => $call) {
				$services->checks = [];
				try { $value = $call(); $result = ['value' => is_object($value) ? ['class' => $value::class, 'properties' => get_object_vars($value)] : $value]; }
				catch (\Throwable $e) { $result = ['exception' => [$e::class, $e->getMessage(), $e->getCode()]]; }
				$report[$enabled ? 'enabled' : 'disabled'][$name] = $result + ['checks' => $services->checks];
			}
		}
		return $report;
	}

	private function scene(): array {
		$app = (new FakeMailMessageApp())->withAccount(1, 'alice', 'Work', 'alice@example.com')
			->withFolder(1, ['id' => 5, 'name' => 'INBOX'])->withFolder(1, ['id' => 6, 'name' => 'Sent'])
			->withFolder(1, ['id' => 7, 'name' => 'Drafts'])->withFolder(1, ['id' => 8, 'name' => 'Archive']);
		$app->accountSettings[1] = ['sent' => 6, 'drafts' => 7];
		$structure = FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'), new FakeMimePart('text/plain', 'notes.txt', 'attachment')]);
		$app->withMessage(5, ['id' => 50, 'sentAt' => 1790000000, 'subject' => 'Résumé', 'body' => 'Olá mundo', 'structure' => $structure, 'attachmentContents' => ['2' => 'Olá attachment']])
			->withMessage(5, ['id' => 51, 'sentAt' => 1790000000, 'subject' => 'Same date']);
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		foreach (['read', 'messages', 'attachments', 'flags', 'move', 'draft', 'send', 'save'] as $grant) { $policy->setGrant('alice', 'mail', $grant, true); }
		return [MailMessageToolsRegistryTest::module($this, $app, '5.12.5', $policy), $app];
	}

	private function observe(FakeMailMessageApp $app, callable $call): array {
		$app->journal->exchangeArray([]);
		try { $out = ['result' => $call()]; }
		catch (\Throwable $e) { $out = ['exception' => ['class' => $e::class, 'message' => $e->getMessage(), 'code' => $e->getCode()] + ($e instanceof \OCA\Mcp\Mcp\Confirmation\PlanChanged ? ['plan' => $e->plan] : [])]; }
		return $out + ['calls' => $app->journal->getArrayCopy(), 'outbox' => $app->outbox, 'drafts' => $app->drafts, 'moved' => $app->moved];
	}
}
