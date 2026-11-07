<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Modules\Mail\Integration\MailAttachmentProbe;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\UnknownToolException;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Preserves the existing version-only PEEK condition and the independent per-user app gate. */
final class MailAttachmentProbeTest extends TestCase {
	public function testOnlyTheExistingPeekVersionsAndKnownKeyAreSupported(): void {
		foreach (['' => false, '5.0.0' => false, '5.10.10' => false, '5.10.11' => true, '5.11.0' => true, '5.12.5' => true] as $version => $expected) {
			$apps = $this->createStub(IAppManager::class);
			$apps->method('getAppVersion')->willReturn($version);
			$probe = new MailAttachmentProbe($apps);
			self::assertSame(['mail.attachments'], $probe->keys());
			self::assertSame($expected, $probe->supports('mail.attachments'), $version);
			self::assertFalse($probe->supports('mail.unknown'));
		}
	}

	public function testAProbeFailureIsClosedAndLoggedOnlyOncePerRequest(): void {
		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willThrowException(new \RuntimeException('version unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');
		$registry = new CapabilityRegistry([new MailAttachmentProbe($apps)], $logger);
		self::assertFalse($registry->supports('mail.attachments'));
		self::assertFalse($registry->supports('mail.attachments'));
		self::assertFalse($registry->supports('mail.unregistered'));
	}

	public function testDisabledMailHidesAndRefusesAttachmentsDespiteVersionAndGrant(): void {
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$policy->setGrant('alice', 'mail', 'attachments', true);
		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('5.12.5');
		$apps->method('isEnabledForUser')->willReturn(false);
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturn($this->createStub(IUser::class));
		$module = MailMessageToolsRegistryTest::module($this, new FakeMailMessageApp(), '5.12.5', $policy);
		$registry = new ToolRegistry([$module], $policy, $apps, $users, new NullLogger(), null, MailTestModule::capabilities($apps));
		self::assertNotContains('mail_read_attachment', array_column($registry->list('alice'), 'name'));
		$this->expectException(UnknownToolException::class);
		$registry->call('mail_read_attachment', ['message_id' => 50, 'attachment_id' => '2'], 'alice');
	}
}
