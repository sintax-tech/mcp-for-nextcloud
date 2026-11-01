<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Mail module publishes its two read tools of accounts and folders first (the message, attachment and flag tools
 * that follow are covered by their registry tests), the grant catalog offers reading Mail and one write, flags, and
 * the registry hides both tools while Mail is off for the user or older than MCP supports.
 */
final class MailModuleTest extends TestCase {
	/** The tools of the module, in tools/list order. */
	private const TOOLS = ['mail_list_accounts', 'mail_list_mailboxes'];

	/**
	 * Every tool carries the Mail app id and a closed schema; all but the flag, move, draft and send tools read and never ask for confirm.
	 *
	 * @return void
	 */
	public function testTheModulePublishesTwoReadToolsOfTheMailApp(): void {
		$definitions = $this->module()->definitions();

		$this->assertSame(self::TOOLS, array_slice(array_column($definitions, 'name'), 0, 2));
		foreach ($definitions as $definition) {
			$this->assertSame('mail', $definition['module']);
			$this->assertSame('mail', $definition['app']);
			$this->assertFalse($definition['inputSchema']['additionalProperties']);
			$write = ['mail_set_flags' => 'flags', 'mail_move_message' => 'move', 'mail_create_draft' => 'draft', 'mail_send_message' => 'send'][$definition['name']] ?? null;
			if ($write !== null) {
				$this->assertSame($write, $definition['operation']);
				$this->assertTrue(WriteGate::isWrite($definition));
				continue;
			}
			$this->assertSame('read', $definition['operation']);
			$this->assertFalse(WriteGate::isWrite($definition), $definition['name']);
			$this->assertArrayNotHasKey('confirm', (array)$definition['inputSchema']['properties']);
		}
		$this->assertSame(['account_id'], $definitions[1]['inputSchema']['required']);
		$this->assertTrue(is_subclass_of(MailModule::class, PreviewsWrites::class), 'the flag, move, draft and send tools have plans of their own');
	}

	/**
	 * The grant catalog for Mail: `read`, granted by default like the other reads, `messages` (see
	 * MailMessageToolsRegistryTest), `attachments` (see MailAttachmentToolsRegistryTest) and the writes `flags` (see
	 * MailFlagToolsRegistryTest), `move` (see MailMoveToolsRegistryTest), `draft` (see MailDraftToolsRegistryTest) and `send` (see
	 * MailSendToolsRegistryTest).
	 *
	 * @return void
	 */
	public function testTheCatalogOffersReadingMailAndItsWrites(): void {
		$this->assertSame(['read', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'], GrantPolicy::CATALOG['mail']);
		$this->assertNotContains('mail', GrantPolicy::DENIED_READS);
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$this->assertTrue($policy->granted('alice', 'mail', 'read'));
	}

	/**
	 * A tool name the module does not have is an invalid call.
	 *
	 * @return void
	 */
	public function testAnUnknownToolIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->module()->call('mail_send', [], 'alice');
	}

	/**
	 * The registry lists both tools when Mail 5.0.0 or later is enabled for the user.
	 *
	 * @return void
	 */
	public function testTheToolsAreListedWhenMailIsEnabledInASupportedVersion(): void {
		$this->assertSame(self::TOOLS, $this->listed(true, '5.0.0'));
		$this->assertSame(self::TOOLS, $this->listed(true, '5.12.5'));
	}

	/**
	 * The registry hides both tools when Mail is off for the user or older than the minimum, and refuses a call.
	 *
	 * @return void
	 */
	public function testTheToolsAreHiddenWhenMailIsOffOrTooOld(): void {
		$this->assertSame([], $this->listed(false, '5.12.5'));
		$this->assertSame([], $this->listed(true, '4.4.0'));

		$this->expectException(InvalidArgumentException::class);
		$this->registry(false, '5.12.5')->call('mail_list_accounts', [], 'alice');
	}

	/**
	 * The Mail module over an empty app in memory.
	 *
	 * @return MailModule the module
	 */
	private function module(): MailModule {
		return MailMessageToolsRegistryTest::module($this, new FakeMailMessageApp());
	}

	/**
	 * Names of the Mail tools the registry lists for alice.
	 *
	 * @param bool $enabled whether Mail is enabled for the user
	 * @param string $version installed Mail version
	 * @return list<string> listed tool names
	 */
	private function listed(bool $enabled, string $version): array {
		return array_values(array_intersect(array_column($this->registry($enabled, $version)->list('alice'), 'name'), self::TOOLS));
	}

	/**
	 * The registry with the Mail module only.
	 *
	 * @param bool $enabled whether Mail is enabled for the user
	 * @param string $version installed Mail version
	 * @return ToolRegistry the registry
	 */
	private function registry(bool $enabled, string $version): ToolRegistry {
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'mail' && $enabled);
		$apps->method('getAppVersion')->willReturn($version);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		return new ToolRegistry([$this->module()], $policy, $apps, $users, $this->createMock(LoggerInterface::class));
	}
}
