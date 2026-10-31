<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\UnknownToolException;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../stubs/OCA/Mail/Events/MessageFlaggedEvent.php';

/**
 * mail_set_flags through the real registry: a write of its own grant, `mail.flags`, which starts denied (stored policies
 * from before it existed hold no value for it, so they deny it too) and opens no read; a call without confirm is a plan
 * that changes nothing, and the confirmed call with the plan's state changes the flag.
 */
final class MailFlagToolsRegistryTest extends TestCase {
	/** The flag tool. */
	private const TOOL = 'mail_set_flags';

	/**
	 * The flags grant is a write of the catalog that starts denied; no read grant includes it.
	 *
	 * @return void
	 */
	public function testTheFlagsGrantStartsDenied(): void {
		$this->assertContains('flags', GrantPolicy::CATALOG['mail']);
		$this->assertFalse(GrantPolicy::defaultGranted('mail', 'flags'));
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		foreach (['read', 'messages', 'attachments'] as $operation) {
			$policy->setGrant('alice', 'mail', $operation, true);
		}
		$this->assertFalse($policy->granted('alice', 'mail', 'flags'));
	}

	/**
	 * Each grant opens only its own tools: flags alone lists the flag tool and no message tool, the read grants
	 * never list the flag tool.
	 *
	 * @return void
	 */
	public function testTheFlagsGrantOpensOnlyTheFlagTool(): void {
		$this->assertNotContains(self::TOOL, $this->listed(['messages' => true, 'attachments' => true]));
		$this->assertSame(['mail_list_accounts', 'mail_list_mailboxes', self::TOOL], $this->listed(['flags' => true]));
	}

	/**
	 * Listed, the tool is a write: no read-only hint and a confirm argument; without the grant a call is an unknown tool.
	 *
	 * @return void
	 */
	public function testTheToolIsAWriteThatNeedsItsGrant(): void {
		[$registry] = $this->registry(['flags' => true]);
		$tool = array_values(array_filter($registry->list('alice'), static fn (array $tool): bool => $tool['name'] === self::TOOL))[0];
		$this->assertFalse($tool['annotations']['readOnlyHint'] ?? false);
		$this->assertArrayHasKey('confirm', (array)$tool['inputSchema']['properties']);
		$this->assertArrayHasKey(PlanState::ARGUMENT, (array)$tool['inputSchema']['properties']);

		[$denied] = $this->registry(['messages' => true]);
		$this->expectException(UnknownToolException::class);
		$denied->call(self::TOOL, ['message_id' => 7, 'seen' => true], 'alice');
	}

	/**
	 * A call without confirm is the plan, with nothing contacted; the confirmed call with its state changes the flag.
	 *
	 * @return void
	 */
	public function testThePlanChangesNothingAndTheConfirmedCallChangesTheFlag(): void {
		[$registry, $app] = $this->registry(['flags' => true]);
		$app->withFolder(1, ['id' => 5, 'name' => 'INBOX'])->withMessage(5, ['id' => 50]);
		$plan = $registry->call(self::TOOL, ['message_id' => 50, 'seen' => true], 'alice');
		$this->assertTrue($plan['structuredContent']['requiresConfirmation']);
		$this->assertSame([], $app->imapCommands());
		$this->assertSame([], $app->messages[50]['serverFlags']);

		$done = $registry->call(self::TOOL, ['message_id' => 50, 'seen' => true, 'confirm' => true, PlanState::ARGUMENT => $plan['structuredContent'][PlanState::ARGUMENT]], 'alice');
		$this->assertArrayNotHasKey('isError', $done);
		$this->assertSame(['\\seen'], $app->messages[50]['serverFlags']);
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
		$app->flagEvent = \OCA\Mail\Events\MessageFlaggedEvent::class;
		$module = MailMessageToolsRegistryTest::module($this, $app, '5.12.5', $policy);
		return [new ToolRegistry([$module], $policy, $apps, $users, $this->createMock(LoggerInterface::class)), $app];
	}
}
