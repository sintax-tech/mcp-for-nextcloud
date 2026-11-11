<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Modules\Mail\Editing\MailFlagTools;
use OCA\Mcp\Modules\Mail\Creation\MailDraftTools;
use OCA\Mcp\Modules\Mail\Moving\MailMoveTools;
use OCA\Mcp\Modules\Mail\Sending\MailSendTools;
use OCA\Mcp\Modules\Mail\Reading\MailAttachmentTools;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Reading\MailMessageTools;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The four message tools of the Mail module through the real registry: read tools under the separate `mail.messages`
 * grant, which starts denied. Without it they are neither listed nor callable (a call reads like an unknown tool);
 * with it they are read-only (no confirm, readOnlyHint, no plan) and return data. Mail off or too old hides them even
 * with the grant.
 */
final class MailMessageToolsRegistryTest extends TestCase {
	/** Accounts and folders, in tools/list order. */
	private const FOLDER_TOOLS = ['mail_list_accounts', 'mail_list_mailboxes'];

	/** The message tools, in tools/list order. */
	private const MESSAGE_TOOLS = ['mail_list_messages', 'mail_search_messages', 'mail_read_message', 'mail_read_thread'];

	/**
	 * The Mail module over an app in memory, in UTC.
	 *
	 * @param TestCase $test the test that needs it, for its stubs
	 * @param FakeMailMessageApp $app the app
	 * @param string $mailVersion installed Mail version the attachment tool sees
	 * @param GrantPolicy|null $policy grants the flag plan reads; a fresh in-memory policy when null
	 * @return MailModule the module
	 */
	public static function module(TestCase $test, FakeMailMessageApp $app, string $mailVersion = '5.12.5', ?GrantPolicy $policy = null): MailModule {
		$stub = \Closure::bind(static fn (string $type): object => TestCase::createStub($type), null, TestCase::class);
		$config = $stub(IConfig::class);
		$config->method('getUserValue')->willReturn('UTC');
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$apps = $stub(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($mailVersion);
		$links = FakeMailLinks::create($test);
		$policy ??= InMemoryConfig::policy((new InMemoryConfig())->mock($test), new InMemoryOAuthStore());
		return MailTestModule::build($app, new UserTimezone($config), $links, new PlanState($config), $policy, $apps, $stub(ITempManager::class), $stub(IEventDispatcher::class));
	}

	/**
	 * The message tools read and name the messages grant; the tools of accounts and folders do not, and the attachment
	 * tool that follows them names its own grant (see MailAttachmentToolsRegistryTest).
	 *
	 * @return void
	 */
	public function testTheMessageToolsAreReadToolsOfTheMessagesGrant(): void {
		$definitions = self::module($this, new FakeMailMessageApp())->definitions();
		$this->assertSame([...self::FOLDER_TOOLS, ...self::MESSAGE_TOOLS, 'mail_read_attachment', 'mail_set_flags', 'mail_move_message', 'mail_create_draft', 'mail_send_message'], array_column($definitions, 'name'));
		// The module's writes are covered by MailFlagToolsRegistryTest, MailMoveToolsRegistryTest, MailDraftToolsRegistryTest
		// and MailSendToolsRegistryTest.
		$definitions = array_values(array_filter($definitions, static fn (array $definition): bool => !in_array($definition['name'], ['mail_set_flags', 'mail_move_message', 'mail_create_draft', 'mail_send_message'], true)));
		foreach ($definitions as $definition) {
			$this->assertSame('read', $definition['operation']);
			$this->assertFalse(WriteGate::isWrite($definition), $definition['name']);
			$expected = in_array($definition['name'], self::MESSAGE_TOOLS, true) ? ['messages'] : null;
			$expected = $definition['name'] === 'mail_read_attachment' ? ['attachments'] : $expected;
			$this->assertSame($expected, $definition['grantAnyOf'] ?? null, $definition['name']);
		}
	}

	/**
	 * The messages grant starts denied, so `mail.read` alone never opens the messages.
	 *
	 * @return void
	 */
	public function testTheMessagesGrantStartsDenied(): void {
		$this->assertFalse(GrantPolicy::defaultGranted('mail', 'messages'));
		$this->assertSame(['logs', 'activity', 'notifications', 'availability', 'status'], GrantPolicy::DENIED_READS);
		$this->assertNotContains('mail', GrantPolicy::DENIED_READS);
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$this->assertTrue($policy->granted('alice', 'mail', 'read'));
		$this->assertFalse($policy->granted('alice', 'mail', 'messages'));
	}

	/**
	 * With only the default grants, the registry lists accounts and folders but no message tool, and refuses a call
	 * of one exactly like a tool that does not exist.
	 *
	 * @return void
	 */
	public function testTheMessageToolsNeedTheMessagesGrant(): void {
		[$registry] = $this->registry(true, '5.12.5', false);
		$this->assertSame(self::FOLDER_TOOLS, $this->mailTools($registry), 'neither the message tools nor the attachment tool');

		$this->expectException(InvalidArgumentException::class);
		$registry->call('mail_list_messages', ['mailbox_id' => 1], 'alice');
	}

	/**
	 * With the messages grant the registry lists the message tools as read-only, without the confirm argument.
	 *
	 * @return void
	 */
	public function testWithTheGrantTheMessageToolsAreReadOnly(): void {
		[$registry] = $this->registry(true, '5.0.0', true);
		$listed = array_values(array_filter($registry->list('alice'), static fn (array $tool): bool => in_array($tool['name'], self::MESSAGE_TOOLS, true)));
		$this->assertSame(self::MESSAGE_TOOLS, array_column($listed, 'name'));
		foreach ($listed as $tool) {
			$this->assertTrue($tool['annotations']['readOnlyHint'], $tool['name']);
			$this->assertFalse($tool['annotations']['destructiveHint'] ?? false, $tool['name']);
			$this->assertArrayNotHasKey('confirm', (array)$tool['inputSchema']['properties'], $tool['name']);
		}
	}

	/**
	 * Through the registry, a granted message read runs without confirm and returns data, never a plan.
	 *
	 * @return void
	 */
	public function testAGrantedCallReturnsDataWithoutAPlan(): void {
		[$registry, $app] = $this->registry(true, '5.12.5', true);
		$app->withFolder(1, ['id' => 5, 'name' => 'INBOX'])->withMessage(5, ['id' => 50]);
		$result = $registry->call('mail_list_messages', ['mailbox_id' => 5], 'alice');
		$this->assertArrayNotHasKey('isError', $result);
		$this->assertArrayNotHasKey('structuredContent', $result);
		$this->assertSame([50], array_column(json_decode($result['content'][0]['text'], true)['messages'], 'id'));
	}

	/**
	 * The registry hides the message tools when Mail is off for the user or older than the minimum, even with the grant.
	 *
	 * @return void
	 */
	public function testTheMessageToolsAreHiddenWhenMailIsOffOrTooOld(): void {
		$this->assertSame([], $this->mailTools($this->registry(false, '5.12.5', true)[0]));
		$this->assertSame([], $this->mailTools($this->registry(true, '4.4.0', true)[0]));
	}

	/**
	 * Names of the Mail tools the registry lists for alice.
	 *
	 * @param ToolRegistry $registry the registry
	 * @return list<string> listed tool names
	 */
	private function mailTools(ToolRegistry $registry): array {
		return array_values(array_filter(array_column($registry->list('alice'), 'name'), static fn (string $name): bool => str_starts_with($name, 'mail_')));
	}

	/**
	 * The registry with the Mail module only, alice owning account 1.
	 *
	 * @param bool $enabled whether Mail is enabled for the user
	 * @param string $version installed Mail version
	 * @param bool $messages whether alice was granted mail.messages
	 * @return array{ToolRegistry, FakeMailMessageApp} the registry and the app behind it
	 */
	private function registry(bool $enabled, string $version, bool $messages): array {
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		if ($messages) {
			$policy->setGrant('alice', 'mail', 'messages', true);
		}
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'mail' && $enabled);
		$apps->method('getAppVersion')->willReturn($version);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$app = (new FakeMailMessageApp())->withAccount(1, 'alice', 'Work', 'alice@example.com');
		return [new ToolRegistry([self::module($this, $app, $version, $policy)], $policy, $apps, $users, $this->createMock(LoggerInterface::class)), $app];
	}
}
