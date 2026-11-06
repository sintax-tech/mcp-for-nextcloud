<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Integration\PollsMutationSurface;
use OCA\Mcp\Modules\Polls\Integration\PollsSurface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Four official source pins, not substitutes for native runtime cells. */
final class PollsMutationSourceContractTest extends TestCase {
	/** @return iterable<string,array{string,array<string,mixed>}> Hash-verified extracted records. */
	public static function pins(): iterable {
		$j = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/polls/polls-mutation.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($j as $v => $r) { yield $v => [$v, $r]; }
	}
	/** @param string $version Polls pin. @param array<string,mixed> $record Official source. @return void */
	#[DataProvider('pins')]
	public function testAllCalledSignaturesAreExact(string $version, array $record): void {
		foreach (PollsMutationSurface::methods($version !== '8.6.3') as $class => $methods) {
			foreach ($methods as $method => [$types]) {
				self::assertSame($types, $record['surfaces'][$class]['types'][$method], $version . ' ' . $class . '::' . $method);
			}
		}
		$m = $record['methods']['Service/ShareService.php::sendInvitation'];
		self::assertStringContainsString($version === '8.6.3' ? '?SentResult &$sentResult = null' : 'SentResult &$sentResult = new SentResult()', $m);
		self::assertStringContainsString($version === '8.6.3' ? '): ?SentResult' : '): SentResult', $m);
		$name = $version === '8.6.3' ? 'getUserObject' : 'createUserObject';
		self::assertCount(7, $record['surfaces'][PollsMutationSurface::USER_MAPPER]['types'][$name]);
		self::assertSame(['int', 'array'], $record['surfaces'][PollsSurface::POLLS]['types']['update']);
	}
	/** @param string $version Polls pin. @param array<string,mixed> $record Official source. @return void */
	#[DataProvider('pins')]
	public function testKnownNativeEffectsAreDeclared(string $version, array $record): void {
		$m = $record['methods'];
		self::assertStringContainsString('return $default;', $m['Model/Settings/AppSettings.php::getBooleanSetting']);
		self::assertStringContainsString('|| $this->isMember(', $m['Model/Settings/AppSettings.php::getBooleanSetting']);
		self::assertStringContainsString('getPublicSharesAllowed', $m['Service/ShareService.php::' . ($version === '8.6.3' ? 'add' : 'createPublicShare')]);
		self::assertStringContainsString('setOwner($this->userSession->getCurrentUserId())', $m['Service/OptionService.php::add']);
		self::assertStringContainsString('setAnonymousSafe(', $m['Db/Poll.php::deserializeArray']);
		self::assertStringContainsString('$value = -1', $m['Db/Poll.php::setAnonymousSafe']);
		self::assertStringContainsString('setDeleted($restore ? 0 : time())', $m['Service/OptionService.php::delete']);
		self::assertStringNotContainsString('->delete(', $m['Service/OptionService.php::delete']);
		self::assertStringContainsString('sendInvitation', $m['Service/ShareService.php::sendInvitation']);
		self::assertStringNotContainsString('PERMISSION_POLL_EDIT', $m['Service/ShareService.php::sendInvitation']);
		self::assertStringContainsString('setUserId($token)', $m[$version === '8.6.3' ? 'Service/ShareService.php::createNewShare' : 'Db/Share.php::setFromUserObject']);
	}
}
