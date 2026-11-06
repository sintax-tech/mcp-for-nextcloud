<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Modules\Mail\Creation\{MailDraftHtml, MailDraftHtmlPurifier, MailDraftTools};
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Presentation\MailDraftPlanRenderer;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Mcp\Confirmation\{PlanState, PlanChanged};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\{IConfig, App\IAppManager};
use PHPUnit\Framework\TestCase;

/** The confirmed effect uses the saved fragment and boolean; changed or refused plans append nothing. */
final class MailDraftHtmlConfirmationTest extends TestCase {
	private FakeMailMessageApp $app;
	private MailDraftTools $tools;

	protected function setUp(): void {
		$this->app = (new FakeMailMessageApp())->withAccount(11, 'alice', 'Work', 'alice@example.com');
		$this->app->accountSettings = [11 => ['drafts' => 3]];
		$this->app->withFolder(11, ['id' => 3, 'name' => 'Drafts']);
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('5.12.5');
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$engine = new class implements MailDraftHtmlPurifier {
			public function purify(string $html): string { return $html; }
		};
		$this->tools = new MailDraftTools($this->app, new MailGateway($this->app), FakeMailLinks::create($this),
			new PlanState($config), $policy, $apps, new MailDraftHtml($engine));
	}

	public function testConfirmedHtmlMatchesTheEntirePlanAndEquivalentSourcesShareState(): void {
		$args = ['account_id' => 11, 'html' => true, 'body' => '<p onclick="x()">' . str_repeat('a', 2200) . '</p>'];
		$plan = $this->tools->preview('mail_create_draft', $args, 'alice');
		$this->assertSame([], $this->app->drafts);
		$this->assertSame('<p>' . str_repeat('a', 2200) . '</p>', $plan['draft']['body']);
		$this->assertTrue($plan['draft']['html']);
		$this->assertStringContainsString(str_repeat('a', 2200), (new MailDraftPlanRenderer())->render($plan));
		$cleanArgs = ['body' => $plan['draft']['body']] + $args;
		$this->assertSame($plan['plan_state'], $this->tools->preview('mail_create_draft', $cleanArgs, 'alice')['plan_state']);
		$this->tools->call('mail_create_draft', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		$this->assertCount(1, $this->app->drafts);
		$this->assertTrue($this->app->drafts[0]['html']);
		$this->assertSame($plan['draft']['body'], $this->app->drafts[0]['body']);
	}

	public function testChangedModeBodyAndOldStateAppendNothing(): void {
		$args = ['account_id' => 11, 'html' => true, 'body' => '<p>approved</p>'];
		$plan = $this->tools->preview('mail_create_draft', $args, 'alice');
		foreach ([['html' => false], ['body' => '<p>different</p>'], ['plan_state' => str_repeat('0', 64)]] as $change) {
			try {
				$this->tools->call('mail_create_draft', $change + $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
				$this->fail('changed state wrote a draft');
			} catch (PlanChanged) {
				$this->assertSame([], $this->app->drafts);
			}
		}
	}

	public function testEmptyModeAndTextDefaults(): void {
		foreach ([[], ['html' => false], ['html' => true]] as $mode) {
			$plan = $this->tools->preview('mail_create_draft', $mode + ['account_id' => 11], 'alice');
			$this->assertSame($mode['html'] ?? false, $plan['draft']['html']);
			$this->assertSame('', $plan['draft']['body']);
		}
	}

	public function testUnreadableHtmlNeverPlansOrAppendsEvenOnConfirmation(): void {
		foreach (['<script>x()</script>', '<p></p>', '<br>', '   '] as $body) {
			foreach ([false, true] as $confirm) {
				try {
					$args = ['account_id' => 11, 'html' => true, 'body' => $body, 'plan_state' => str_repeat('a', 64)];
					$confirm ? $this->tools->call('mail_create_draft', $args, 'alice') : $this->tools->preview('mail_create_draft', $args, 'alice');
					$this->fail('unreadable body accepted');
				} catch (ToolFailure) {
					$this->assertSame([], $this->app->drafts);
				}
			}
		}
	}
}
