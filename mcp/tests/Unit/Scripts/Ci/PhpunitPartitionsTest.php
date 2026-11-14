<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Ci;

use OCA\Mcp\Scripts\Ci\PhpunitCoverage;
use OCA\Mcp\Scripts\Ci\PhpunitProfiles;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/ci/PhpunitProfiles.php';
require_once __DIR__ . '/../../../../scripts/ci/PhpunitCoverage.php';

/** The existing partition planner and verifier must account for every active identity without hiding omissions. */
final class PhpunitPartitionsTest extends TestCase {
	/** @var string Disposable reports, never a production source tree. */
	private string $work;

	/** @return void Create a small representative official inventory and matching JUnit reports. */
	protected function setUp(): void {
		$this->work = sys_get_temp_dir() . '/mcp-ci-partitions-' . bin2hex(random_bytes(6));
		mkdir($this->work);
		$prefix = 'OCA\\Mcp\\Tests\\Unit\\Contract\\EffectContracts\\';
		$identities = [];
		foreach (['CalendarEffectsContractTest::testCreationOfAnEvent', 'CalendarWritesEffectsContractTest::testMovingAnEvent',
			'CalendarWritesEffectsContractTest::testDeletingAnEvent', 'CalendarWritesEffectsContractTest::testTransferringAnEvent',
			'TasksEffectsContractTest::testCreationOfATask', 'TasksWritesEffectsContractTest::testCompletionOfATask',
			'AdminSettingsEffectsContractTest::testCheckoutLimit', 'FilesUploadRestoreEffectsContractTest::testUploadAndTheCreateRoute'] as $name) {
			$identities[] = $prefix . $name;
		}
		$identities[] = 'OCA\\Mcp\\Tests\\Unit\\Scripts\\Dev\\MapCoverageTest::testCoverage';
		$identities[] = 'OCA\\Mcp\\Tests\\Unit\\ExampleTest::testSurface with data set "NC34"';
		$identities[] = 'OCA\\Mcp\\Tests\\Unit\\ExampleTest::testSurface with data set "NC35"';
		$inventory = '<testCaseClassList>';
		$reports = array_fill_keys(array_keys(PhpunitProfiles::filters()), '');
		foreach ($identities as $identity) {
			[$class, $method] = explode('::', $identity);
			$id = preg_replace('/ with data set "(.*)"$/', '#$1', $identity);
			$escape = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES);
			$inventory .= '<testCaseClass name="' . $escape($class) . '"><testCaseMethod name="' . $escape($method) . '" id="' . $escape($id) . '"/></testCaseClass>';
			foreach (PhpunitProfiles::filters() as $profile => $filter) {
				if (preg_match($filter, $identity) === 1) {
					$reports[$profile] .= '<testcase class="' . $escape($class) . '" name="' . $escape($method) . '"/>';
				}
			}
		}
		file_put_contents($this->work . '/inventory.xml', $inventory . '</testCaseClassList>');
		foreach ($reports as $profile => $cases) {
			file_put_contents($this->work . '/' . $profile . '.xml', '<testsuites><testsuite>' . $cases . '</testsuite></testsuites>');
		}
	}

	/** @return void Remove only this test's disposable reports. */
	protected function tearDown(): void {
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->work, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->work);
	}

	/** @return void All four profiles retain ownership; the verifier accepts their exact executed union. */
	public function testPrepareAndVerifyKeepEveryIdentityExactlyOnce(): void {
		$this->expectOutputRegex('/Complete official inventory verified: 11 cases, no gaps or duplicates/');
		PhpunitCoverage::prepare($this->work, PhpunitProfiles::filters());
		$parts = json_decode(file_get_contents($this->work . '/inventory.json'), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame(['oracle' => 6, 'recordings' => 2, 'dev' => 1, 'supported' => 2], array_map('count', $parts));
		PhpunitCoverage::verify($this->work);
	}

	/** @return void An omitted active dataset cannot disappear through a supported-profile change. */
	public function testVerifyRejectsAMissingDataset(): void {
		$this->expectOutputRegex('/supported: 2 inventoried cases/');
		PhpunitCoverage::prepare($this->work, PhpunitProfiles::filters());
		file_put_contents($this->work . '/supported.xml', '<testsuites/>');
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('inventory mismatch');
		PhpunitCoverage::verify($this->work);
	}

	/** @return void A duplicated execution also fails even if a global count would have looked plausible. */
	public function testVerifyRejectsADuplicateDataset(): void {
		$this->expectOutputRegex('/supported: 2 inventoried cases/');
		PhpunitCoverage::prepare($this->work, PhpunitProfiles::filters());
		$file = $this->work . '/supported.xml';
		$xml = file_get_contents($file);
		preg_match('/<testcase[^>]*\/>/', $xml, $match);
		file_put_contents($file, str_replace('</testsuite>', $match[0] . '</testsuite>', $xml));
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Test executed twice');
		PhpunitCoverage::verify($this->work);
	}

	/** @return void Overlapping filters cannot silently assign an active identity to two profiles. */
	public function testPrepareRejectsOverlappingSelectors(): void {
		$filters = PhpunitProfiles::filters();
		$filters['supported'] = '~.*~';
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Omitted or duplicated inventory identity');
		PhpunitCoverage::prepare($this->work, $filters);
	}

	/** @return void Run forwards the supported selector and startup limits to the existing PHPUnit command. */
	public function testRunForwardsTheSupportedProfileToPhpunit(): void {
		$app = $this->work . '/app';
		mkdir($app . '/vendor/bin', recursive: true);
		file_put_contents($app . '/vendor/bin/phpunit', '<?php file_put_contents($argv[2] . ".invocation", json_encode([$argv, ini_get("post_max_size"), ini_get("upload_max_filesize")]));');
		self::assertSame(0, PhpunitProfiles::run($app, $this->work, 'supported'));
		[$args, $post, $upload] = json_decode(file_get_contents($this->work . '/phpunit.xml.invocation'), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame(['50M', '50M'], [$post, $upload]);
		self::assertContains(PhpunitProfiles::filters()['supported'], $args);
		self::assertContains($this->work . '/supported.xml', $args);
	}

	/** @return void Unsupported run profiles fail before spawning PHPUnit or altering any report. */
	public function testRunRejectsAnUnknownProfile(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unknown PHPUnit profile');
		PhpunitProfiles::run(dirname(__DIR__, 4), $this->work, 'unrecognised');
	}
}
