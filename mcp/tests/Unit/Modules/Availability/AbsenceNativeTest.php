<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Availability;

use OCA\Mcp\Modules\Availability\Integration\AbsenceCapabilities;
use OCA\Mcp\Modules\Availability\Integration\AbsenceSurface;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** The reviewed server source behind the read, and the fail-closed probe over it. */
final class AbsenceNativeTest extends TestCase {
	/** @return void every pinned file is the reviewed one and is byte-identical in both server tags */
	public function testPinnedSourcesAreTheReviewedBytesOfBothTags(): void {
		AbsenceNativeRig::load();
		$pins = json_decode((string)file_get_contents(AbsenceNativeRig::dir() . 'sources.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(['34.0.4', '35.0.1'], $pins['tags']);
		self::assertSame('d30e63bace5ad5f79b8179f69cf4ee50f3cb4ed99c41a22377f86ee53cefc556', $pins['files']['AbsenceService.php']['sha256']);
		self::assertSame('9dbb0c8b0eec91070ad7f6c34ee9bf3b66369745923d0054a444029590fda55d', $pins['files']['AbsenceMapper.php']['sha256']);
		foreach ($pins['files'] as $file => $pin) {
			self::assertSame(hash_file('sha256', AbsenceNativeRig::dir() . $file), $pin['sha256'], $file);
			self::assertSame(['https://raw.githubusercontent.com/nextcloud/server/v34.0.4/' . $pin['path'], 'https://raw.githubusercontent.com/nextcloud/server/v35.0.1/' . $pin['path']], $pin['sources']);
		}
	}

	/**
	 * AbsenceService.php:127-133 getAbsence only calls AbsenceMapper::findByUserId, and AbsenceMapper.php:55-74 is a
	 * single SELECT by user_id: the read path has no insert, update, delete, job or event.
	 *
	 * @return void
	 */
	public function testTheNativeReadIsOneSelectByUserId(): void {
		AbsenceNativeRig::load();
		self::assertSame(['findByUserId'], $this->calls(AbsenceSurface::SERVICE, 'getAbsence', 127, 133));
		$select = $this->calls('OCA\\DAV\\Db\\AbsenceMapper', 'findByUserId', 55, 74);
		self::assertSame(['getQueryBuilder', 'select', 'from', 'getTableName', 'where', 'expr', 'eq', 'createNamedParameter', 'findEntity'], array_values(array_unique($select)));
		// isInEffect (151-154) compares with the clock; toOutOufOfficeData (Absence.php:64-86) only builds a value.
		self::assertSame(['getTime', 'getStartDate', 'getEndDate'], $this->calls(AbsenceSurface::SERVICE, 'isInEffect', 151, 154));
		self::assertNotContains('insert', $this->calls(AbsenceSurface::ENTITY, 'toOutOufOfficeData', 64, 86));
	}

	/**
	 * AbsenceService.php:44-106 createOrUpdateAbsence selects the actor's row (54), inserts or updates that entity and
	 * dispatches Scheduled/Changed (67-81), then schedules start/end jobs with this absence's own ID as argument
	 * (83-103). It never removes a job or deletes a row; that is clearAbsence (111-125), which this app never calls.
	 *
	 * @return void
	 */
	public function testTheNativeWriteSchedulesOnlyItsOwnJobsAndRemovesNothing(): void {
		AbsenceNativeRig::load();
		$calls = $this->calls(AbsenceSurface::SERVICE, 'createOrUpdateAbsence', 44, 106);
		self::assertSame(['findByUserId', 'insert', 'dispatchTyped', 'update', 'scheduleAfter'], array_values(array_intersect(array_unique($calls),
			['findByUserId', 'insert', 'update', 'delete', 'remove', 'removeById', 'dispatchTyped', 'scheduleAfter', 'add'])));
		self::assertSame(['delete', 'remove'], array_values(array_intersect($this->calls(AbsenceSurface::SERVICE, 'clearAbsence', 111, 125), ['delete', 'remove'])));
		$found = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/lib/Modules/Availability', \FilesystemIterator::SKIP_DOTS)) as $file) {
			$tokens = array_values(array_filter(\PhpToken::tokenize((string)file_get_contents($file->getPathname())), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
			foreach ($tokens as $i => $token) {
				if ($i > 0 && $token->is(T_STRING) && in_array($token->text, ['clearAbsence', 'remove', 'removeById', 'delete', 'deleteByUserId', 'insert', 'update'], true)
					&& in_array($tokens[$i - 1]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) $found[] = $file->getFilename() . ':' . $token->text;
			}
		}
		self::assertSame([], $found, 'the module never clears an absence, removes jobs or writes rows itself');
	}

	/** @return void the surface accepts the pinned sources and refuses each broken signature */
	public function testSurfaceFailsClosed(): void {
		AbsenceNativeRig::load();
		self::assertTrue((new AbsenceSurface())->supported());
		self::assertFalse((new AbsenceSurface(service: AbsenceServiceWithoutRead::class))->supported());
		self::assertFalse((new AbsenceSurface(service: AbsenceServiceWithStaticRead::class))->supported());
		self::assertFalse((new AbsenceSurface(entity: AbsenceWithoutConversion::class))->supported());
		self::assertFalse((new AbsenceSurface(timezone: TimezoneWithoutDefault::class))->supported());
		self::assertFalse((new AbsenceSurface(service: 'OCA\\DAV\\Service\\Missing'))->supported());
		self::assertTrue((new AbsenceSurface())->writable());
		$readOnly = new AbsenceSurface(service: AbsenceServiceWithoutWrite::class);
		self::assertTrue($readOnly->supported());
		self::assertFalse($readOnly->writable(), 'the write key needs createOrUpdateAbsence with the verified signature');
	}

	/** @return void the probe needs DAV enabled for someone and the intact surface; no version comparison */
	public function testProbe(): void {
		AbsenceNativeRig::load();
		foreach ([[true, new AbsenceSurface(), true], [false, new AbsenceSurface(), false], [true, new AbsenceSurface(entity: AbsenceWithoutConversion::class), false]] as [$enabled, $surface, $expected]) {
			$apps = $this->createMock(IAppManager::class);
			$apps->method('isEnabledForAnyone')->willReturn($enabled);
			$apps->expects(self::never())->method('isEnabledForUser');
			$probe = new AbsenceCapabilities($apps, $surface);
			self::assertSame(['availability.native', 'availability.write'], $probe->keys());
			self::assertSame($expected, $probe->supports('availability.native'));
			self::assertSame($expected, $probe->supports('availability.write'));
			self::assertFalse($probe->supports('availability.other'));
		}
	}

	/**
	 * @param string $class pinned class
	 * @param string $method method
	 * @param int $start reviewed first line
	 * @param int $end reviewed last line
	 * @return list<string> method names called in the body, in order
	 */
	private function calls(string $class, string $method, int $start, int $end): array {
		$reflection = new ReflectionMethod($class, $method);
		self::assertSame([$start, $end], [$reflection->getStartLine(), $reflection->getEndLine()], "$class::$method moved");
		$lines = array_slice(file((string)$reflection->getFileName()), $start - 1, $end - $start + 1);
		$tokens = array_values(array_filter(\PhpToken::tokenize('<?php ' . implode('', $lines)), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
		$calls = [];
		foreach ($tokens as $i => $token) {
			if ($i > 0 && $token->is(T_STRING) && in_array($tokens[$i - 1]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && ($tokens[$i + 1]->text ?? '') === '(') $calls[] = $token->text;
		}
		return $calls;
	}
}

/** Mutant: the read method is gone. */
final class AbsenceServiceWithoutRead {
	/** @param \OCP\User\IOutOfOfficeData $absence data @return bool never */
	public function isInEffect(\OCP\User\IOutOfOfficeData $absence): bool { return false; }
}

/** Mutant: the read path only, without createOrUpdateAbsence. */
final class AbsenceServiceWithoutWrite {
	/** @param string $userId user @return \OCA\DAV\Db\Absence|null never */
	public function getAbsence(string $userId): ?\OCA\DAV\Db\Absence { return null; }
	/** @param \OCP\User\IOutOfOfficeData $absence data @return bool never */
	public function isInEffect(\OCP\User\IOutOfOfficeData $absence): bool { return false; }
}

/** Mutant: the read method became static. */
final class AbsenceServiceWithStaticRead {
	/** @param string $userId user @return \OCA\DAV\Db\Absence|null never */
	public static function getAbsence(string $userId): ?\OCA\DAV\Db\Absence { return null; }
	/** @param \OCP\User\IOutOfOfficeData $absence data @return bool never */
	public function isInEffect(\OCP\User\IOutOfOfficeData $absence): bool { return false; }
}

/** Mutant: an entity without the native conversion. */
final class AbsenceWithoutConversion extends \OCP\AppFramework\Db\Entity {
	/** @var string stored owner */
	protected string $userId = '';
}

/** Mutant: no server-default time zone. */
final class TimezoneWithoutDefault {
	/** @param string $userId user @return string|null never */
	public function getUserTimezone(string $userId): ?string { return null; }
}
