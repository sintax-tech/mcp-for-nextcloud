<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FormsSourceReaders;
use OCA\Mcp\Tests\Architecture\Dependencies\DynamicReferences;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/** Finite positive/negative controls for the RE-18 movement proof, without changing any existing checker test. */
final class FormsMoveProofTest extends TestCase {
	public function testOnlyExactPairedSourceReadersArePrepared(): void {
		$reader = 'tests/Unit/Contract/NextcloudApi/Forms/FormsCreationContractTest.php';
		$old = 'lib/Tools/Forms/Creation/FormsCreateNativeFactory.php';
		$new = 'lib/Modules/Forms/Integration/FormsCreateNativeFactory.php';
		$source = "<?php file_get_contents(__DIR__ . '/../../../../../$old');";
		self::assertSame("<?php file_get_contents(dirname(__DIR__, 5) . '/$old');", FormsSourceReaders::prepare($source, $reader, [$old => $new]));
		foreach ([[], [$old => 'lib/Modules/Forms/Other.php'], [$old => $old]] as $pairs) {
			self::assertSame($source, FormsSourceReaders::prepare($source, $reader, $pairs));
		}
		self::assertSame($source, FormsSourceReaders::prepare($source, 'tests/Other.php', [$old => $new]));
		foreach ([str_replace('../../../../../', '../../../../', $source), str_replace($old, 'lib/Other.php', $source), str_replace('__DIR__', '$root', $source)] as $unknown) {
			self::assertSame($unknown, FormsSourceReaders::prepare($unknown, $reader, [$old => $new]));
		}
		$comment = '<?php /* ' . substr($source, 6) . ' */';
		self::assertSame($comment, FormsSourceReaders::prepare($comment, $reader, [$old => $new]));
	}

	public function testDynamicMapperAcceptsOnlyItsTwoExactHistoricalLocations(): void {
		$source = file_get_contents(__DIR__ . '/../../../../lib/Modules/Forms/Integration/SubmissionReads.php');
		$edge = ['target' => 'OCA\\Forms\\Db\\', 'line' => 28];
		foreach (['lib/Tools/Forms/Results/SubmissionReads.php', 'lib/Modules/Forms/Integration/SubmissionReads.php'] as $path) {
			self::assertCount(4, DynamicReferences::resolve($path, $source, $edge));
		}
		foreach (['lib/Modules/Forms/Reading/SubmissionReads.php', 'lib/Tools/Other/Results/SubmissionReads.php', 'lib/Modules/Forms/Integration/Other.php'] as $path) {
			try { DynamicReferences::resolve($path, $source, $edge); self::fail('Unpaired mapper site accepted'); }
			catch (\RuntimeException $error) { self::assertStringContainsString('Unreviewed', $error->getMessage()); }
		}
	}
}
