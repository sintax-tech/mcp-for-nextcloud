<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use OCA\Mcp\Modules\Mail\Locating\MailCacheGeneration;
use PHPUnit\Framework\TestCase;

/**
 * The Horde classes the Mail message tools use come from the vendor tree of the Mail app, so the main fixtures do not
 * carry them. This narrow contract checks them against mail-horde-surfaces.json (generate-mail-horde.php), generated
 * from the same pinned Mail releases as the Mail fixtures: every class, method, passed parameter, constant and magic
 * property in NextcloudApiUsage::HORDE exists in every covered major, every trailing parameter left to its default is
 * reviewed, and lib/ names no other Horde class.
 */
final class MailHordeSurfaceContractTest extends TestCase {
	/** Directory of the fixtures. */
	private const FIXTURES = __DIR__ . '/../../../fixtures/nextcloud-api';

	/** The library sources. */
	private const LIB = __DIR__ . '/../../../../lib';

	/**
	 * The generated surfaces, by major.
	 *
	 * @return array<string, array{mail:string, packages:array<string, string>, classes:array<string, array{methods:array<string, list<string>>, constants:list<string>, properties:list<string>}>}>
	 */
	private static function surfaces(): array {
		return json_decode((string)file_get_contents(self::FIXTURES . '/mail-horde-surfaces.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * The surfaces cover exactly the pinned majors, each from its pinned Mail release, with the Horde packages it ships.
	 *
	 * @return void
	 */
	public function testTheSurfacesComeFromThePinnedMailReleases(): void {
		$pins = json_decode((string)file_get_contents(self::FIXTURES . '/mail-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$surfaces = self::surfaces();
		$this->assertSame(array_keys($pins), array_keys($surfaces));
		foreach ($surfaces as $major => $surface) {
			$this->assertSame($pins[$major]['mail']['version'], $surface['mail'], "NC$major");
			$this->assertSame(['bytestream/horde-imap-client', 'bytestream/horde-mime'], array_keys($surface['packages']), "NC$major");
			$this->assertSame(array_keys(NextcloudApiUsage::HORDE), array_keys($surface['classes']), "NC$major: run generate-mail-horde.php");
		}
	}

	/**
	 * In every covered major the folder sync tokens the Mail app stores are what MailCacheGeneration reads: base64 of
	 * comma-joined key.value pairs, the UIDVALIDITY under the key it expects, and Horde's own sync refusing a token of
	 * another UIDVALIDITY.
	 *
	 * @return void
	 */
	public function testTheSyncTokenFormatIsTheOneMailCacheGenerationReads(): void {
		foreach (self::surfaces() as $major => $surface) {
			$this->assertSame(['uidvalidityKey' => MailCacheGeneration::UIDVALIDITY_KEY, 'joinedByComma' => true, 'syncChecksUidvalidity' => true],
				$surface['syncToken'] ?? null, "NC$major (Mail {$surface['mail']}): run generate-mail-horde.php");
		}
	}

	/**
	 * Every used member exists in every covered major with the parameters the app passes; nothing is missing.
	 *
	 * @return void
	 */
	public function testEveryUsedMemberExistsInEveryCoveredMajor(): void {
		foreach (self::surfaces() as $major => $surface) {
			$this->assertSame([], self::problems($surface['classes']), "NC$major (Mail {$surface['mail']})");
		}
	}

	/**
	 * Every reviewed trailing parameter still exists in some covered major: no stale review.
	 *
	 * @return void
	 */
	public function testEveryReviewedTrailingParameterStillExists(): void {
		foreach (NextcloudApiUsage::HORDE as $class => $usage) {
			foreach (array_keys($usage['trailing'] ?? []) as $parameter) {
				$found = false;
				foreach (self::surfaces() as $surface) {
					foreach ($surface['classes'][$class]['methods'] as $declared) {
						$found = $found || in_array($parameter, array_map(static fn (string $p): string => rtrim(ltrim($p, '.'), '?'), $declared), true);
					}
				}
				$this->assertTrue($found, "$class has no \$$parameter in any covered major: drop it from the review");
			}
		}
	}

	/**
	 * The rule fails on the changes it exists to catch: a method gone, a parameter renamed, an unreviewed trailing
	 * parameter, a constant or a magic property gone.
	 *
	 * @return void
	 */
	public function testTheRuleCatchesAChangedSurface(): void {
		$classes = self::surfaces()['35']['classes'];
		$this->assertSame([], self::problems($classes));
		$changes = [
			static fn (array $c): array => self::without($c, 'Horde_Imap_Client_Fetch_Query', 'size'),
			static fn (array $c): array => self::without($c, 'Horde_Imap_Client_Data_Fetch', 'getSize'),
			static fn (array $c): array => array_replace_recursive($c, ['Horde_Imap_Client_Socket' => ['methods' => ['fetch' => ['box', 'query', 'options?']]]]),
			static fn (array $c): array => array_replace_recursive($c, ['Horde_Imap_Client_Ids' => ['methods' => ['__construct' => ['ids?', 'sequence?', 'strict?']]]]),
			static fn (array $c): array => array_replace($c, ['Horde_Imap_Client' => ['methods' => [], 'constants' => [], 'properties' => []]]),
			static fn (array $c): array => array_replace($c, ['Horde_Imap_Client_Data_Envelope' => ['methods' => [], 'constants' => [], 'properties' => []]]),
		];
		foreach ($changes as $index => $change) {
			$this->assertNotSame([], self::problems($change($classes)), "change $index went unnoticed");
		}
	}

	/**
	 * lib/ names no Horde class outside the list, and only the body and attachment preflights, the flag store and the move
	 * store name any.
	 *
	 * @return void
	 */
	public function testOnlyTheListedHordeClassesAreUsed(): void {
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB, \FilesystemIterator::SKIP_DOTS));
		$users = [];
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			preg_match_all("/'(Horde_\\w+)'/", (string)file_get_contents($file->getPathname()), $matches);
			foreach ($matches[1] as $class) {
				$this->assertArrayHasKey($class, NextcloudApiUsage::HORDE, $file->getPathname() . " names $class");
				$users[basename($file->getPathname())] = true;
			}
		}
		$names = array_keys($users);
		sort($names);
		$this->assertSame(['MailAttachmentPreflight', 'MailBodyPreflight', 'MailFlagStore', 'MailMoveStore', 'MailSavePreflight'], array_map(static fn (string $name): string => pathinfo($name, PATHINFO_FILENAME), $names));
	}

	/**
	 * What does not match the usage list in one major's surface.
	 *
	 * @param array<string, array{methods:array<string, list<string>>, constants:list<string>, properties:list<string>}> $classes the surface
	 * @return list<string> the problems, empty when everything used is there
	 */
	private static function problems(array $classes): array {
		$problems = [];
		foreach (NextcloudApiUsage::HORDE as $class => $usage) {
			$surface = $classes[$class] ?? null;
			if ($surface === null) {
				$problems[] = "$class is missing";
				continue;
			}
			foreach ($usage['methods'] ?? [] as $method => $passed) {
				$declared = $surface['methods'][$method] ?? null;
				if ($declared === null) {
					$problems[] = "$class::$method() is missing";
					continue;
				}
				$names = array_map(static fn (string $p): string => rtrim(ltrim($p, '.'), '?'), $declared);
				if (array_slice($names, 0, count($passed)) !== $passed) {
					$problems[] = "$class::$method() takes " . implode(', ', $names) . ', not ' . implode(', ', $passed);
				}
				foreach (array_slice($declared, count($passed)) as $trailing) {
					$name = rtrim(ltrim($trailing, '.'), '?');
					if (!str_ends_with($trailing, '?') || !isset($usage['trailing'][$name])) {
						$problems[] = "$class::$method() has the unreviewed trailing \$$name";
					}
				}
			}
			foreach ($usage['constants'] ?? [] as $constant) {
				if (!in_array($constant, $surface['constants'], true)) {
					$problems[] = "$class::$constant is missing";
				}
			}
			foreach ($usage['properties'] ?? [] as $property) {
				if (!in_array($property, $surface['properties'], true)) {
					$problems[] = "$class->$property is not documented";
				}
			}
		}
		return $problems;
	}

	/**
	 * A surface without one method.
	 *
	 * @param array<string, array<string, mixed>> $classes the surface
	 * @param string $class class
	 * @param string $method method to remove
	 * @return array<string, array<string, mixed>> the changed surface
	 */
	private static function without(array $classes, string $class, string $method): array {
		unset($classes[$class]['methods'][$method]);
		return $classes;
	}
}
