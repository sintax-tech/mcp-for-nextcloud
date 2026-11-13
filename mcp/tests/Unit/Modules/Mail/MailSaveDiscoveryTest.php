<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\Tools\Mail\{FakeMailMessageApp, MailMessageToolsRegistryTest, MailTestModule};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\{ToolRegistry, UnknownToolException};
use PHPUnit\Framework\TestCase;

/** Opt-in save discovery and direct calls use the same installation/app/grant gates. */
final class MailSaveDiscoveryTest extends TestCase {
	/** @param string $version Installed Mail. @param bool $enabled User app enablement. @param bool $save Save grant. @return ToolRegistry Real registry over existing Mail fixtures. */
	private function registry(string $version, bool $enabled, bool $save): ToolRegistry {
		$config = new InMemoryConfig();
		$policy = InMemoryConfig::policy($config->mock($this), new InMemoryOAuthStore());
		$policy->setGrant('alice', 'mail', 'save', $save);
		$apps = $this->createMock(\OCP\App\IAppManager::class);
		$apps->method('getAppVersion')->willReturn($version); $apps->method('isEnabledForUser')->willReturn($enabled);
		$users = $this->createMock(\OCP\IUserManager::class); $users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$app = new FakeMailMessageApp();
		$module = MailMessageToolsRegistryTest::module($this, $app, $version, $policy);
		return new ToolRegistry([$module], $policy, $apps, $users, new \Psr\Log\NullLogger(), null, MailTestModule::capabilities($apps));
	}
	public function testSaveIsDeniedByDefaultAndIndependentOfOtherGrants(): void {
		self::assertFalse(GrantPolicy::defaultGranted('mail', 'save'));
		self::assertNotContains('mail_save_attachment', array_column($this->registry('5.12.5', true, false)->list('alice'), 'name'));
		$listed = $this->registry('5.10.11', true, true)->list('alice');
		$save = array_values(array_filter($listed, static fn ($t) => $t['name'] === 'mail_save_attachment'));
		self::assertCount(1, $save); self::assertFalse($save[0]['annotations']['readOnlyHint']);
		self::assertArrayHasKey('confirm', $save[0]['inputSchema']['properties']);
		self::assertSame('boolean', $save[0]['inputSchema']['properties']['replace']['type']);
		self::assertNotContains('replace', $save[0]['inputSchema']['required']);
		self::assertNotContains('mail_read_message', array_column($listed, 'name'));
	}
	public function testUnsupportedInstallationsHideAndRefuseDirectCalls(): void {
		foreach ([['5.10.10',true],['',true],['unreadable',true],['99garbage',true],['5.12.5',false]] as [$version,$enabled]) {
			$registry = $this->registry($version, $enabled, true);
			self::assertNotContains('mail_save_attachment', array_column($registry->list('alice'), 'name'));
			try { $registry->call('mail_save_attachment', ['message_id'=>7,'attachment_id'=>'2','path'=>'/saved.pdf','confirm'=>true], 'alice'); self::fail(); }
			catch (UnknownToolException) { self::assertTrue(true); }
		}
	}
}
