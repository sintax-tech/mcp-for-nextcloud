<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Architecture\Dependencies\ClassReferences;
use RuntimeException;

/** Native app names belong to the mapped module Integration directory, regardless of spelling. */
final class NativeAppBoundaryTest extends ArchitectureCase {
	public function testLiveNativeReferencesHaveExactExpiringExceptions(): void { $this->assertBoundary('native-app'); }
	public function testBothLiteralSpellingsAndCatchAndDocTypeAreEdges(): void {
		foreach ([
			"\$x = 'OCA\\Guests\\GuestManager';",
			"\$x = 'OCA\\\\Guests\\\\GuestManager';",
			'try {} catch (\\OCA\\Forms\\Service\\FormService $e) {}',
			'/** @param \\OCA\\Deck\\Db\\Card $card */ function x($card) {}',
			'/** @param array<string, \\OCA\\Deck\\Db\\Card> $cards */ function x($cards) {}',
			"/** @return array{\n * card: \\OCA\\Deck\\Db\\Card\n * } */ function x() {}",
		] as $code) { self::assertNotSame([], self::fixture('lib/Modules/Deck/Reading/X.php', $code)); }
		self::assertSame([], self::fixture('lib/Modules/Deck/Integration/X.php', 'use OCA\\Deck\\Db\\Card;'));
	}
	public function testIncompletePrefixIsDiagnosedAndLiteralConcatenationIsResolved(): void {
		$joined = self::fixture('lib/Modules/Deck/Reading/X.php', "\$x = 'OCA\\\\' . 'Deck\\\\Db\\\\Card';");
		self::assertSame('OCA\\Deck\\Db\\Card', $joined[0]['target']);
		$this->expectException(RuntimeException::class);
		self::fixture('lib/Modules/Deck/Reading/X.php', "\$x = 'OCA\\\\' . \$app . '\\\\Db\\\\Card';");
	}

	public function testDocumentationProseAndSeeAreNotTypes(): void {
		self::assertSame([], ClassReferences::read('<?php /** {@see \\OCA\\Mcp\\Mcp\\Confirmation\\PlanState} @return object as an OCA\\Files\\File */ function x() {}'));
	}
	public function testDynamicSiteRejectsChangedPrefixOrCallerSet(): void {
		$path = 'lib/Tools/Forms/Results/SubmissionReads.php';
		$source = self::tree()->files[$path];
		self::assertCount(4, \OCA\Mcp\Tests\Architecture\Dependencies\DynamicReferences::resolve($path, $source, ['target' => 'OCA\\Forms\\Db\\', 'line' => 28]));
		foreach ([str_replace('SubmissionMapper', 'OtherMapper', $source), str_replace(" . \$name);", " . \$name . 'Other');", $source), str_replace('Forms\\\\Db', 'Forms\\\\Other', $source)] as $mutant) {
			try { \OCA\Mcp\Tests\Architecture\Dependencies\DynamicReferences::resolve($path, $mutant, ['target' => 'OCA\\Forms\\Db\\', 'line' => 28]); self::fail('Dynamic change accepted'); }
			catch (RuntimeException $error) { self::assertStringContainsString('dynamic', strtolower($error->getMessage())); }
		}
	}
}
