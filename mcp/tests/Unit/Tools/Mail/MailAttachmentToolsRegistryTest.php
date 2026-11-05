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
use OCA\Mcp\Modules\Mail\Integration\MailAttachmentSupport;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\UnknownToolException;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * mail_read_attachment through the real registry: a read tool of its own grant, `mail.attachments`, which starts
 * denied and is independent of `mail.messages` (each opens only its own tools, in the four combinations), and a tool
 * that does not exist below Mail 5.10.11, whatever the grants.
 */
final class MailAttachmentToolsRegistryTest extends TestCase {
	/** The message tools, in tools/list order. */
	private const MESSAGE_TOOLS = ['mail_list_messages', 'mail_search_messages', 'mail_read_message', 'mail_read_thread'];

	/** The attachment tool. */
	private const TOOL = 'mail_read_attachment';

	/**
	 * The attachments grant is a read operation of the catalog that starts denied; reading Mail and its messages do not
	 * include it.
	 *
	 * @return void
	 */
	public function testTheAttachmentsGrantStartsDenied(): void {
		$this->assertContains('attachments', GrantPolicy::CATALOG['mail']);
		$this->assertFalse(GrantPolicy::defaultGranted('mail', 'attachments'));
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$policy->setGrant('alice', 'mail', 'messages', true);
		$this->assertFalse($policy->granted('alice', 'mail', 'attachments'));
	}

	/**
	 * The four combinations of the two grants: each opens its own tools only.
	 *
	 * @return void
	 */
	public function testEachGrantOpensOnlyItsOwnTools(): void {
		$this->assertSame([], $this->listed(false, false));
		$this->assertSame(self::MESSAGE_TOOLS, $this->listed(true, false));
		$this->assertSame([self::TOOL], $this->listed(false, true));
		$this->assertSame([...self::MESSAGE_TOOLS, self::TOOL], $this->listed(true, true));
	}

	/**
	 * Without the attachments grant a call reads like a tool that does not exist, even with the messages grant.
	 *
	 * @return void
	 */
	public function testWithoutTheGrantACallIsAnUnknownTool(): void {
		[$registry] = $this->registry('5.12.5', true, false);
		$this->expectException(UnknownToolException::class);
		$registry->call(self::TOOL, ['message_id' => 7, 'attachment_id' => '2'], 'alice');
	}

	/**
	 * With the grant the tool is read-only, has no confirm argument, and a call returns data, never a plan, without the
	 * messages grant.
	 *
	 * @return void
	 */
	public function testWithTheGrantTheToolIsReadOnlyAndReturnsData(): void {
		[$registry, $app] = $this->registry('5.12.5', false, true);
		$tool = array_values(array_filter($registry->list('alice'), static fn (array $tool): bool => $tool['name'] === self::TOOL))[0];
		$this->assertTrue($tool['annotations']['readOnlyHint']);
		$this->assertFalse($tool['annotations']['destructiveHint'] ?? false);
		$this->assertArrayNotHasKey('confirm', (array)$tool['inputSchema']['properties']);

		$structure = FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'), new FakeMimePart('text/plain', 'notes.txt', 'attachment')]);
		$app->withFolder(1, ['id' => 5, 'name' => 'INBOX'])->withMessage(5, ['id' => 50, 'structure' => $structure, 'attachmentContents' => ['2' => 'Agenda']]);
		$result = $registry->call(self::TOOL, ['message_id' => 50, 'attachment_id' => '2'], 'alice');
		$this->assertArrayNotHasKey('isError', $result);
		$this->assertArrayNotHasKey('structuredContent', $result);
		$this->assertSame('Agenda', json_decode($result['content'][0]['text'], true)['attachment']['text']);
	}

	/**
	 * Below Mail 5.10.11, or with a version the server cannot tell, the tool is not defined, even with both grants;
	 * from 5.10.11 on it is.
	 *
	 * @return void
	 */
	public function testTheToolNeedsAMailThatReadsAttachmentsWithPeek(): void {
		$this->assertSame('5.10.11', MailAttachmentSupport::MINIMUM_VERSION);
		foreach (['5.0.0', '5.9.3', '5.10.10', ''] as $version) {
			$this->assertNotContains(self::TOOL, $this->listed(true, true, $version), "Mail '$version'");
		}
		foreach (['5.10.11', '5.11.0', '5.12.5'] as $version) {
			$this->assertContains(self::TOOL, $this->listed(true, true, $version), "Mail $version");
		}
		[$registry] = $this->registry('5.10.10', true, true);
		$this->expectException(UnknownToolException::class);
		$registry->call(self::TOOL, ['message_id' => 7, 'attachment_id' => '2'], 'alice');
	}

	/**
	 * The message and attachment tools the registry lists for alice.
	 *
	 * @param bool $messages whether alice was granted mail.messages
	 * @param bool $attachments whether alice was granted mail.attachments
	 * @param string $version installed Mail version
	 * @return list<string> listed tool names
	 */
	private function listed(bool $messages, bool $attachments, string $version = '5.12.5'): array {
		$names = array_column($this->registry($version, $messages, $attachments)[0]->list('alice'), 'name');
		return array_values(array_filter($names, static fn (string $name): bool => in_array($name, [...self::MESSAGE_TOOLS, self::TOOL], true)));
	}

	/**
	 * The registry with the Mail module only, alice owning account 1, Mail enabled in that version.
	 *
	 * @param string $version installed Mail version
	 * @param bool $messages whether alice was granted mail.messages
	 * @param bool $attachments whether alice was granted mail.attachments
	 * @return array{ToolRegistry, FakeMailMessageApp} the registry and the app behind it
	 */
	private function registry(string $version, bool $messages, bool $attachments): array {
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$policy->setGrant('alice', 'mail', 'messages', $messages);
		$policy->setGrant('alice', 'mail', 'attachments', $attachments);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'mail');
		$apps->method('getAppVersion')->willReturn($version);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$app = (new FakeMailMessageApp())->withAccount(1, 'alice', 'Work', 'alice@example.com');
		$module = MailMessageToolsRegistryTest::module($this, $app, $version);
		return [new ToolRegistry([$module], $policy, $apps, $users, $this->createMock(LoggerInterface::class), null, MailTestModule::capabilities($apps)), $app];
	}
}
