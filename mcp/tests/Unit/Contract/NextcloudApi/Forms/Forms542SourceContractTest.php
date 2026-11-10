<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

use PHPUnit\Framework\TestCase;

/** Binds the 5.4.2 write bodies and the type refusal after locking to the official source archive. */
final class Forms542SourceContractTest extends TestCase {
	/** Normalized executable bodies from the SHA-checked official 5.4.2 tar, using FormsBehavior::body. */
	private const BODIES = [
		'newForm' => '59a198fd67d95164a08bbe6eff58bec4513423b9e61fb0f0cf5ada3d54b4ae83',
		'updateForm' => 'd510fdda947541032436b90c65f21b02dc17dd330ecebcc8800f36cf247b291b',
		'newQuestion' => '446a8b6c4718cd0b3b8732978b0c727d747e22c2a30daef109b2482bac53d474',
		'updateQuestion' => '8482c3a563a290d8b62e7846f25f6e35a55e3841ce43b55f207276228cd07bd8',
		'newOption' => '0c36b87f3e6800903a263b9e3e9a66e754ae8b7f0447f21683976752e3a9e9d2',
		'reorderQuestions' => '9bafb0764b7a178efccde5a3a2f54d020274d3689f6ba14d7dbfa9db6631f940',
		'reorderOptions' => '117c391d758e47a7ec97f6ac9d89d22cfb1acfe73b9e75ad2beebd5750f03600',
		'deleteQuestion' => 'b7f39c76ba7be1817a9ba954f35032b516c08eec867aa8aed589073204b72ae2',
		'deleteOption' => 'c93c2a4cc83131268eb33f1fcfb12224a564752fe4da87292e38a9c8baf581e3',
		'updateOption' => '61a1a12d7b6534808165e28e55134b7190bb21177ce707297bbed1bfaf5c13b4',
	];

	/** The other write bodies and constructor signatures remain identical to 5.4.1.
	 * @return void
	 */
	public function testPinnedBodiesAndUnchangedSources(): void {
		$old = self::fixture('5.4.1'); $new = self::fixture('5.4.2');
		self::assertSame('c12cc4e05dd9c41cfee8df75a8cc0f759e9a95169fefabd617713a934da7943a', $new['sha256']);
		self::assertSame(FormsSourcePins::all()['5.4.2'], $new['sha256']);
		self::assertSame($old['classes'], $new['classes']);
		foreach ($new['sources'] as $name => $source) {
			self::assertSame($source['sha256'], hash('sha256', $source['source']), $name);
			if ($name !== 'Controller/ApiController') { self::assertSame($old['sources'][$name], $source, $name); }
		}
		foreach (self::BODIES as $method => $hash) {
			$body = FormsBehavior::body($new['sources']['Controller/ApiController']['source'], $method);
			self::assertSame($hash, hash('sha256', $body), $method);
			if ($method !== 'updateQuestion') {
				self::assertSame(FormsBehavior::body($old['sources']['Controller/ApiController']['source'], $method), $body, $method);
			}
		}
	}

	/** ApiController:662 locks before :687–689 refuses type, before any question update.
	 * @return void
	 */
	public function testTypeGuardRunsAfterTheNativeLock(): void {
		$source = self::fixture('5.4.2')['sources']['Controller/ApiController']['source'];
		$body = FormsBehavior::body($source, 'updateQuestion');
		$guard = "if(array_key_exists('id',\$keyValuePairs)||array_key_exists('formId',\$keyValuePairs)||array_key_exists('type',\$keyValuePairs)){";
		self::assertStringContainsString($guard, $body);
		$guardAt = strpos($body, $guard);
		$lockAt = strpos($body, '$this->formsService->obtainFormLock($form);');
		$throwAt = strpos($body, 'thrownewOCSForbiddenException', $guardAt);
		self::assertNotFalse($lockAt); self::assertNotFalse($throwAt);
		self::assertLessThan($guardAt, $lockAt);
		self::assertLessThan(strpos($body, '$this->questionMapper->update('), $throwAt);
		self::assertStringContainsString('type', substr($body, $throwAt, strpos($body, ';', $throwAt) - $throwAt));
		self::assertStringNotContainsString($guard, FormsBehavior::body(self::fixture('5.4.1')['sources']['Controller/ApiController']['source'], 'updateQuestion'));
	}

	/** Reads a pinned source fixture without executing upstream PHP.
	 * @param string $version Exact fixture pin.
	 * @return array<string,mixed> Source and signature evidence.
	 */
	private static function fixture(string $version): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../../fixtures/nextcloud-api/forms/' . $version . '.json'), true, 512, JSON_THROW_ON_ERROR);
	}
}
