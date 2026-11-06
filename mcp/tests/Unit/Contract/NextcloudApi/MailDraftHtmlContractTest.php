<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use PHPUnit\Framework\TestCase;

/** Frozen source evidence for HTML drafts and engine autoload across all seven supported Mail pins. */
final class MailDraftHtmlContractTest extends TestCase {
	/** @return void */
	public function testEveryPinnedSourceUsesTheHtmlBodyWithoutSanitizingAndAutoloadsTheEngine(): void {
		$directory = __DIR__ . '/../../../fixtures/nextcloud-api/';
		$contract = json_decode(file_get_contents($directory . 'mail-draft-html.json'), true, 512, JSON_THROW_ON_ERROR);
		$pins = json_decode(file_get_contents($directory . 'mail-draft-bcc.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($pins), array_keys($contract));
		foreach ($pins as $version => $pin) {
			$row = $contract[$version];
			$this->assertSame($pin['sha256'], $row['sha256']);
			$this->assertSame($pin['url'], $row['url']);
			$this->assertSame(8, $row['isHtmlPosition']);
			$this->assertTrue($row['nativeDefault']);
			$this->assertTrue($row['autoloadsEngine']);
			$this->assertFalse($row['saveSanitizes']);
			$this->assertTrue($row['readingRegistersImageDataScheme']);
			$this->assertSame(version_compare($version, '5.9.0', '<')
				? 'getBody->setContent->getContent->setHtmlBody' : 'getBody->setHtmlBody', $row['bodyPath']);
			$this->assertMatchesRegularExpression('/^4\.(18|19)\.0$/', $row['purifierVersion']);
		}
		$this->assertSame('4.18.0', $contract['5.0.0']['purifierVersion']);
		$this->assertSame('4.19.0', $contract['5.12.5']['purifierVersion']);
	}
}
