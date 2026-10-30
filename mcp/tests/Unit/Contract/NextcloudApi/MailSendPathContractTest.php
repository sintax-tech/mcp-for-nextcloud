<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use OCA\Mcp\Tools\Mail\Send\MailSendOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The send tool relies on the Mail app's send path being the same in every pinned release. mail-send-path.json
 * (generate-mail-send-path.php) records it from the official archives pinned by SHA256: the handlers of the send chain
 * in order, the guard that never sends again a message whose earlier send was unclear, the SMTP codes recorded as a
 * definite failure, the status values MailSendOutcome reads, that the background Outbox job only takes messages with a
 * send time, that the SMTP envelope gets Bcc while the transmitted headers do not, and that Horde aborts before DATA when
 * a recipient is refused.
 */
final class MailSendPathContractTest extends TestCase {
	/**
	 * The checked releases, by version.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function releases(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/mail-send-path.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Each release is pinned like the other Mail contracts and was read by the generator; the pins of the main fixtures are
	 * among them.
	 *
	 * @return void
	 */
	public function testEveryReleaseIsPinnedAndRead(): void {
		$releases = self::releases();
		foreach ($releases as $version => $release) {
			$this->assertStringEndsWith('/v' . $version . '/mail-v' . $version . '.tar.gz', $release['url']);
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $release['sha256']);
			$this->assertIsArray($release['chain'] ?? null, "$version: run generate-mail-send-path.php");
		}
		$sources = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/mail-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($sources as $major => $source) {
			$this->assertSame($source['mail']['sha256'], $releases[$source['mail']['version']]['sha256'] ?? null, "NC$major Mail pin");
		}
	}

	/**
	 * Every release sends through the same chain with the same guard and the same definite-failure codes, which is what
	 * MailSendOutcome reads a status by.
	 *
	 * @return void
	 */
	public function testTheSendPathIsTheOneMailSendOutcomeReads(): void {
		$first = null;
		foreach (self::releases() as $version => $release) {
			$this->assertSame(['sentMailboxHandler', 'antiAbuseHandler', 'sendHandler', 'copySentMessageHandler', 'flagRepliedMessageHandler'], $release['chain'], "Mail $version");
			$this->assertTrue($release['unclearGuard'], "Mail $version: an unclear send is never sent again");
			$this->assertSame(['INSUFFICIENT_STORAGE', 'OVERQUOTA', 'LOGIN_REQUIREAUTHENTICATION'], $release['retriableCodes'], "Mail $version");
			$this->assertSame(0, $release['typeOutgoing'], "Mail $version");
			$statuses = array_intersect_key($release['statuses'], array_flip([...MailSendOutcome::STATUSES, 'STATUS_ERROR', 'STATUS_RAW']));
			$this->assertCount(count(MailSendOutcome::STATUSES) + 2, $statuses, "Mail $version: every status read exists");
			$first ??= $statuses;
			$this->assertSame($first, $statuses, "Mail $version: same values as the oldest release");
		}
	}

	/**
	 * A message without a send time is never sent again by the background Outbox job; Bcc reaches the envelope, not the
	 * transmitted headers; a refused recipient aborts before DATA. The send tool saves without a send time.
	 *
	 * @return void
	 */
	public function testTheSendPathGuaranteesTheToolReliesOn(): void {
		foreach (self::releases() as $version => $release) {
			$this->assertTrue($release['findDueNeedsSendAt'], "Mail $version");
			$this->assertContains($release['bccEnvelope'], ['_bcc', 'removeHeader'], "Mail $version");
			$this->assertTrue($release['rcptRefusalBeforeData'], "Mail $version");
		}
		$services = (string)file_get_contents(__DIR__ . '/../../../../lib/Tools/Mail/MailServices.php');
		$this->assertStringContainsString('$message->setSendAt(null);', $services);
		$this->assertStringContainsString("\$message->setType(constant(\$class . '::TYPE_OUTGOING'));", $services);
	}
}
