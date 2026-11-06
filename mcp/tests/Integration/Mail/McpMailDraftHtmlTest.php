<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Mail\Creation\MailDraftHtml;
use OCA\Mcp\Modules\Mail\Integration\MailDraftHtmlEngine;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\{Server, App\IAppManager};
use PHPUnit\Framework\Attributes\Group;

/** The real loaded Mail engine, full adversarial corpus and saved multipart draft in a disposable IMAP cell. */
#[Group('mail')]
final class McpMailDraftHtmlTest extends IntegrationTestCase {
	use MailCellRows;

	/** @return void */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Mail cell only (MCP_IT_WITH_MAIL=1)');
		}
		Server::get(IAppManager::class)->loadApp('mail');
	}

	/** @return void */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			Server::get(GrantPolicy::class)->setGrant(MailActor::ALICE, 'mail', 'draft', false);
		}
		parent::tearDown();
	}

	/** @return void */
	public function testTheLoadedMailEngineNeutralizesOrRefusesEveryPayload(): void {
		$sanitizer = new MailDraftHtml(new MailDraftHtmlEngine());
		$corpus = json_decode(file_get_contents(__DIR__ . '/../../fixtures/mail-draft-html-corpus.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($corpus as $row) {
			try {
				$result = $sanitizer->sanitize($row['input']);
			} catch (ToolFailure $error) {
				$this->assertArrayNotHasKey('body', $row, $row['name'] . ': ' . $error->getMessage());
				self::markCell('html-corpus ' . $row['name'] . ' refused');
				continue;
			}
			$this->assertFalse($row['refused'] ?? false, $row['name']);
			if (isset($row['body'])) {
				$this->assertSame($row['body'], $result['body'], $row['name']);
			}
			$this->assertSame($result['body'], $sanitizer->sanitize($result['body'])['body'], $row['name']);
			self::markCell('html-corpus ' . $row['name'] . ' neutralized ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		}
	}

	/** @return void */
	public function testConfirmedHtmlIsThePlannedMimePartAndRefusalsAppendNothing(): void {
		Server::get(GrantPolicy::class)->setGrant(MailActor::ALICE, 'mail', 'draft', true);
		$account = $this->account(MailActor::ALICE);
		$args = ['account_id' => $account, 'html' => true, 'bcc' => ['copy@example.com'],
			'body' => '<p onclick="x()">Olá <strong>ação</strong></p><pre>A  B' . "\n" . ' C</pre><img src="https://track.example/x"><script>x()</script>'];
		$imap = $this->imap();
		try {
			$before = $imap->state();
			$outbox = $this->select('mail_local_messages', 'account_id', $account, 'id');
			$plan = $this->call($args);
			$this->assertFalse($plan['isError'] ?? false, json_encode($plan));
			$this->assertSame($before, $imap->state(), 'preview never appends');
			$this->assertTrue($plan['structuredContent']['draft']['html']);
			$done = $this->decoded($this->call($args + ['confirm' => true, 'plan_state' => $plan['structuredContent']['plan_state']]));
			$this->assertFalse($done['sent']);
			$after = $imap->state();
			$this->assertSame($before['Drafts']['exists'] + 1, $after['Drafts']['exists']);
			$raw = $imap->raw('Drafts', $done['uid']);
			$this->assertMatchesRegularExpression('/^Bcc: copy@example.com/mi', $raw);
			MailDraftHtmlMime::assertSaved($this, $raw, $plan['structuredContent']['draft']['body']);
			$this->assertSame($outbox, $this->select('mail_local_messages', 'account_id', $account, 'id'));
			$this->assertSame(array_diff_key($before, ['Drafts' => 0]), array_diff_key($after, ['Drafts' => 0]));
			foreach ([['body' => '<script>x()</script>'], ['body' => '<p></p>']] as $invalid) {
				$this->assertTrue($this->call($invalid + $args)['isError']);
				$this->assertTrue($this->call($invalid + $args + ['confirm' => true, 'plan_state' => $plan['structuredContent']['plan_state']])['isError']);
			}
			$this->assertSame($after, $imap->state(), 'refusals never append');
		} finally {
			$imap->close();
		}
	}

	/** @param array<string,mixed> $arguments call arguments @return array<string,mixed> MCP result */
	private function call(array $arguments): array {
		return MailActor::run(MailActor::ALICE, 'calls', ['calls' => [['mail_create_draft', $arguments]]])[0]['result'];
	}
}
