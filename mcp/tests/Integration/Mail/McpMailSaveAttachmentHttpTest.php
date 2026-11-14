<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCP\Server;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\Group;

/** The existing Mail cells exercise Basic/Bearer plans, binary creation and replacement without changing Mail state. */
#[Group('mail')]
final class McpMailSaveAttachmentHttpTest extends ExclusionTestCase {
	use MailCellRows;
	use MailEndpointTools;

	/** Restores the opt-in grant; disposable cell cleanup owns fixture files. */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') { Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'mail', 'save', false); }
		parent::tearDown();
	}
	/** Plans are metadata-only, confirmation creates intact bytes, and the same occupied name cannot be replaced. */
	public function testSaveThroughBothEndpointCredentialsLeavesMailUnchanged(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') { self::markTestSkipped('Existing disposable Mail cells only'); }
		$policy = Server::get(GrantPolicy::class);
		$account = $this->account(self::ALICE);
		$folder = $this->mailbox($account, 'Attachments');
		$message = $this->subjects($folder)['Attachment csv'];
		$before = $this->cacheHash($account);
		$probe = $this->imap();
		try {
			$imapBefore = $probe->state();
			$root = Server::get(IRootFolder::class)->getUserFolder(self::ALICE);
			foreach (['basic', 'bearer'] as $kind) {
				$policy->setGrant(self::ALICE, 'mail', 'save', false);
				self::assertNotContains('mail_save_attachment', array_column($this->toolsList($kind), 'name'));
				$policy->setGrant(self::ALICE, 'mail', 'save', true);
				$listed = array_values(array_filter($this->toolsList($kind), static fn ($tool) => $tool['name'] === 'mail_save_attachment'));
				self::assertCount(1, $listed); self::assertFalse($listed[0]['annotations']['readOnlyHint']);
				$path = '/mail-save-' . $kind . '-' . bin2hex(random_bytes(6)) . '.csv';
				$args = ['message_id' => $message, 'attachment_id' => '2', 'path' => $path];
				$client = $this->mcp(self::ALICE, $kind);
				$plan = $client->tool('mail_save_attachment', $args);
				self::assertTrue($plan->ok(), $plan->describe()); self::assertFalse($root->nodeExists(ltrim($path, '/')));
				$saved = $client->tool('mail_save_attachment', $args + ['confirm' => true]);
				self::assertTrue($saved->ok(), $saved->describe());
				$receipt = $saved->json(); self::assertTrue($receipt['verified']);
				$file = $root->get(ltrim($path, '/')); $bytes = $file->getContent();
				self::assertSame(base64_decode('bm9tZTtjaWRhZGUNCkpv4287U+NvIFBhdWxvDQo=', true), $bytes, 'Original Latin-1 bytes and CRLF, without text extraction');
				self::assertSame($receipt['sha256'], hash('sha256', $bytes));
				self::assertSame($receipt['size'], strlen($bytes)); self::assertSame($receipt['fileId'], (int)$file->getId());
				$again = $client->tool('mail_save_attachment', $args + ['confirm' => true]);
				self::assertTrue($again->isError()); self::assertSame($bytes, $file->getContent());
			}
			self::assertSame($imapBefore, $probe->state()); self::assertSame($before, $this->cacheHash($account));
		} finally { $probe->close(); }
	}

	/** Without the grant a direct call is an unknown tool; with it the seeded PDF is saved and its size read back. */
	public function testPdfIsSavedWithItsSizeReadBackAndIsNotCallableWithoutTheGrant(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') { self::markTestSkipped('Existing disposable Mail cells only'); }
		$policy = Server::get(GrantPolicy::class);
		$message = $this->subjects($this->mailbox($this->account(self::ALICE), 'Attachments'))['Attachment pdf'];
		$root = Server::get(IRootFolder::class)->getUserFolder(self::ALICE);
		foreach (['basic', 'bearer'] as $kind) {
			$path = '/mail-save-pdf-' . $kind . '-' . bin2hex(random_bytes(6)) . '.pdf';
			$args = ['message_id' => $message, 'attachment_id' => '2', 'path' => $path, 'confirm' => true];
			$policy->setGrant(self::ALICE, 'mail', 'save', false);
			$hidden = $this->mcp(self::ALICE, $kind)->tool('mail_save_attachment', $args);
			self::assertSame(-32602, $hidden->body['error']['code'] ?? null, $hidden->describe());
			self::assertSame('unknown tool; use one listed by tools/list', $hidden->body['error']['data']['rule'] ?? null);
			self::assertFalse($root->nodeExists(ltrim($path, '/')));
			$policy->setGrant(self::ALICE, 'mail', 'save', true);
			$saved = $this->mcp(self::ALICE, $kind)->tool('mail_save_attachment', $args);
			self::assertTrue($saved->ok(), $saved->describe());
			$receipt = $saved->json();
			self::assertTrue($receipt['verified']); self::assertSame('saved', $receipt['outcome']);
			$file = $root->get(ltrim($path, '/'));
			// The one-page invoice.pdf of seed-attachments.sh, byte for byte.
			self::assertSame(590, $file->getSize()); self::assertSame(590, $receipt['size']);
			self::assertSame('36c395ad230b3cb6c7364e9002a81add72d09e1282ee6177904885a108d81019', hash('sha256', $file->getContent()));
			self::assertSame($receipt['sha256'], hash('sha256', $file->getContent()));
		}
	}

	/** replace keeps a verified pre-write copy of the existing file, keeps its id and reads both back. */
	public function testReplaceThroughBothEndpointCredentialsKeepsAVerifiedCopy(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') { self::markTestSkipped('Existing disposable Mail cells only'); }
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'mail', 'save', true);
		$account = $this->account(self::ALICE);
		$message = $this->subjects($this->mailbox($account, 'Attachments'))['Attachment csv'];
		$root = Server::get(IRootFolder::class)->getUserFolder(self::ALICE);
		$expected = base64_decode('bm9tZTtjaWRhZGUNCkpv4287U+NvIFBhdWxvDQo=', true);
		foreach (['basic', 'bearer'] as $kind) {
			$path = '/mail-replace-' . $kind . '-' . bin2hex(random_bytes(6)) . '.csv';
			$file = $root->newFile(ltrim($path, '/'), "old;bytes\r\n");
			$etag = (string)$file->getEtag();
			$args = ['message_id' => $message, 'attachment_id' => '2', 'path' => $path];
			$client = $this->mcp(self::ALICE, $kind);
			$refused = $client->tool('mail_save_attachment', $args + ['confirm' => true]);
			self::assertTrue($refused->isError()); self::assertSame("old;bytes\r\n", $file->getContent());
			$plan = $client->tool('mail_save_attachment', $args + ['replace' => true]);
			self::assertTrue($plan->ok(), $plan->describe()); self::assertSame("old;bytes\r\n", $file->getContent());
			$stale = $client->tool('mail_save_attachment', $args + ['replace' => true, 'etag' => 'stale', 'confirm' => true]);
			self::assertTrue($stale->isError()); self::assertSame($etag, (string)$root->get(ltrim($path, '/'))->getEtag());
			$saved = $client->tool('mail_save_attachment', $args + ['replace' => true, 'etag' => $etag, 'confirm' => true]);
			self::assertTrue($saved->ok(), $saved->describe());
			$receipt = $saved->json();
			self::assertTrue($receipt['verified']); self::assertSame('replaced', $receipt['outcome']);
			$current = $root->get(ltrim($path, '/'));
			self::assertSame($expected, $current->getContent()); self::assertSame((int)$file->getId(), (int)$current->getId());
			self::assertSame($receipt['sha256'], hash('sha256', $expected)); self::assertSame($receipt['fileId'], (int)$current->getId());
			self::assertNotSame('', $receipt['backup']); self::assertTrue($receipt['backupVerified']);
			self::assertSame("old;bytes\r\n", $root->get(ltrim($receipt['backup'], '/'))->getContent());
		}
	}
}
