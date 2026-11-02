<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use PHPUnit\Framework\TestCase;

/**
 * MailFlagEvent dispatches the Mail app's flag event in the shape the installed release builds it in
 * MailManager::flagMessage(). mail-flag-event.json (generate-mail-flag-event.php) records, from the official archives
 * pinned by SHA256, the constructor of OCA\Mail\Events\MessageFlaggedEvent and the third argument flagMessage() passes:
 * the UID (an int) up to 5.10.11, the cached message from 5.11.0 on, the two shapes MailFlagEvent::takesUid() tells
 * apart by the type of that parameter. The older pinned releases (5.0 to 5.9) are checked by the main fixtures
 * (NextcloudApiUsage::POSITIONAL).
 */
final class MailFlagEventContractTest extends TestCase {
	/**
	 * The checked releases, by version.
	 *
	 * @return array<string, array{url:string, sha256:string, constructor?:list<string>, flagMessageThirdArgument?:string}>
	 */
	private static function releases(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/mail-flag-event.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Each release is pinned like the other Mail contracts and was read by the generator.
	 *
	 * @return void
	 */
	public function testEveryReleaseIsPinnedAndRead(): void {
		foreach (self::releases() as $version => $release) {
			$this->assertStringEndsWith('/v' . $version . '/mail-v' . $version . '.tar.gz', $release['url']);
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $release['sha256']);
			$this->assertIsArray($release['constructor'] ?? null, "$version: run generate-mail-flag-event.php");
		}
	}

	/**
	 * Up to 5.10.11 the event takes an int UID, from 5.11.0 on the message; flagMessage() passes exactly that, and the
	 * other parameters stay account, mailbox, flag and set.
	 *
	 * @return void
	 */
	public function testTheTwoShapesAreTheOnesMailFlagEventBuilds(): void {
		$shapes = [
			'5.10.11' => ['int $uid', '$uid'],
			'5.11.0' => ['Message $message', '$message'],
			'5.12.5' => ['Message $message', '$message'],
		];
		foreach (self::releases() as $version => $release) {
			[$third, $argument] = $shapes[$version];
			$this->assertSame(['Account $account', 'Mailbox $mailbox', $third, 'string $flag', 'bool $set'], $release['constructor'], "Mail $version");
			$this->assertSame($argument, $release['flagMessageThirdArgument'], "Mail $version");
		}
		$source = (string)file_get_contents((new \ReflectionClass(\OCA\Mcp\Tools\Mail\Flag\MailFlagEvent::class))->getFileName());
		$this->assertStringContainsString("\$type->getName() === 'int'", $source);
		$this->assertStringContainsString('self::takesUid($eventClass) ? (int)$message->getUid() : $message', $source);
	}
}
