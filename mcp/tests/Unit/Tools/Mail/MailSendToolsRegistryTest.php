<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\UnknownToolException;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * mail_send_message through the real registry: a write of its own grant, `mail.send`, which starts denied (stored
 * policies from before it existed hold no value for it, so they deny it too) and opens no read and no other write; a call
 * without confirm is a plan that sends nothing, and the confirmed call with the plan's state sends the message.
 */
final class MailSendToolsRegistryTest extends TestCase {
	/** The send tool. */
	private const TOOL = 'mail_send_message';

	/**
	 * The send grant is a write of the catalog that starts denied; no other Mail grant includes it.
	 *
	 * @return void
	 */
	public function testTheSendGrantStartsDenied(): void {
		$this->assertContains('send', \OCA\Mcp\Access\Grants\GrantCatalog::CATALOG['mail']);
		$this->assertFalse(GrantPolicy::defaultGranted('mail', 'send'));
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		foreach (['read', 'messages', 'attachments', 'flags', 'move', 'draft'] as $operation) {
			$policy->setGrant('alice', 'mail', $operation, true);
		}
		$this->assertFalse($policy->granted('alice', 'mail', 'send'));
	}

	/**
	 * Send alone lists the send tool and the folder tools it needs, no message tool and no other write; the other grants
	 * never list the send tool.
	 *
	 * @return void
	 */
	public function testTheSendGrantOpensOnlyTheSendTool(): void {
		$this->assertNotContains(self::TOOL, $this->listed(['messages' => true, 'attachments' => true, 'flags' => true, 'move' => true, 'draft' => true]));
		$this->assertSame(['mail_list_accounts', 'mail_list_mailboxes', self::TOOL], $this->listed(['send' => true]));
	}

	/**
	 * Listed, the tool is a write: no read-only hint and a confirm argument; without the grant a call is an unknown tool.
	 *
	 * @return void
	 */
	public function testTheToolIsAWriteThatNeedsItsGrant(): void {
		[$registry] = $this->registry(['send' => true]);
		$tool = array_values(array_filter($registry->list('alice'), static fn (array $tool): bool => $tool['name'] === self::TOOL))[0];
		$this->assertFalse($tool['annotations']['readOnlyHint'] ?? false);
		$this->assertArrayHasKey('confirm', (array)$tool['inputSchema']['properties']);
		$this->assertArrayHasKey(PlanState::ARGUMENT, (array)$tool['inputSchema']['properties']);
		foreach (['attachments', 'in_reply_to', 'alias_id', 'html', 'send_at'] as $extra) {
			$this->assertArrayNotHasKey($extra, (array)$tool['inputSchema']['properties'], "no $extra: not asked for");
		}

		[$denied] = $this->registry(['draft' => true]);
		$this->expectException(UnknownToolException::class);
		$denied->call(self::TOOL, ['account_id' => 1, 'to' => ['bob@example.com']], 'alice');
	}

	/**
	 * A call without confirm is the plan, with nothing sent; the confirmed call with its state sends the message.
	 *
	 * @return void
	 */
	public function testThePlanSendsNothingAndTheConfirmedCallSends(): void {
		[$registry, $app] = $this->registry(['send' => true]);
		$app->withFolder(1, ['id' => 5, 'name' => 'Sent']);
		$app->accountSettings[1] = ['sent' => 5];
		$arguments = ['account_id' => 1, 'to' => ['bob@example.com'], 'subject' => 'Hi'];
		$plan = $registry->call(self::TOOL, $arguments, 'alice');
		$this->assertTrue($plan['structuredContent']['requiresConfirmation']);
		$this->assertSame([], $app->outbox);

		$done = $registry->call(self::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan['structuredContent'][PlanState::ARGUMENT]], 'alice');
		$this->assertArrayNotHasKey('isError', $done);
		$this->assertCount(1, $app->outbox);
	}

	/**
	 * The Mail tools the registry lists for alice.
	 *
	 * @param array<string, bool> $grants mail operations granted to alice
	 * @return list<string> listed tool names
	 */
	private function listed(array $grants): array {
		$names = array_column($this->registry($grants)[0]->list('alice'), 'name');
		return array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'mail_') && $name !== 'mail_read_attachment'));
	}

	/**
	 * The registry with the Mail module only, alice owning account 1, Mail 5.12.5 enabled.
	 *
	 * @param array<string, bool> $grants mail operations granted to alice
	 * @return array{ToolRegistry, FakeMailMessageApp} the registry and the app behind it
	 */
	private function registry(array $grants): array {
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		foreach ($grants as $operation => $granted) {
			$policy->setGrant('alice', 'mail', $operation, $granted);
		}
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'mail');
		$apps->method('getAppVersion')->willReturn('5.12.5');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$app = (new FakeMailMessageApp())->withAccount(1, 'alice', 'Work', 'alice@example.com');
		$module = MailMessageToolsRegistryTest::module($this, $app, '5.12.5', $policy);
		return [new ToolRegistry([$module], $policy, $apps, $users, $this->createMock(LoggerInterface::class)), $app];
	}
}
