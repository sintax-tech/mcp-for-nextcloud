<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Modules\Calendar\Creation\CreateEventDraft;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;

/** A native boolean invitation setting must not turn either plan into an unexpected tool error. */
final class CalendarInvitationConfigTest extends CalendarTestCase {
	/** @return iterable<string, array{string,string|bool|null,bool,bool}> Native typed and legacy settings. */
	public static function settings(): iterable {
		foreach (['calendar_create_event', 'calendar_update_event'] as $tool) {
			foreach (['legacy-on' => ['yes', true], 'legacy-off' => ['no', false], 'boolean-on' => [true, true], 'boolean-off' => [false, false], 'absent' => [null, true]] as $case => [$value, $enabled]) {
				foreach ([false, true] as $lazy) {
					yield "$tool-$case-" . (int)$lazy => [$tool, $value, $enabled, $lazy];
				}
			}
		}
	}

	/**
	 * @param string $tool Published create or update tool.
	 * @param string|bool|null $value Native setting; null means the key has never been stored.
	 * @param bool $enabled Expected invitation switch in the plan.
	 * @param bool $lazy Whether the native setting is stored as a lazy value.
	 * @return void The public registry returns the plan, without sending any DAV request.
	 */
	#[DataProvider('settings')]
	public function testPlansReadTheNativeSettingType(string $tool, string|bool|null $value, bool $enabled, bool $lazy): void {
		$config = $this->createMock(IAppConfig::class);
		$loaded = false;
		$config->method('getValueType')->with('dav', 'sendInvitations')->willReturnCallback(static function (string $app, string $key, ?bool $filter = null) use ($value, $lazy, &$loaded): int {
			if ($value === null || ($lazy && $filter === false)) { throw new AppConfigUnknownKeyException(); }
			$loaded = true;
			return is_bool($value) ? IAppConfig::VALUE_BOOL : IAppConfig::VALUE_STRING;
		});
		$config->method('getValueString')->willReturnCallback(static function () use ($value, &$loaded): string {
			if (is_bool($value)) { throw new AppConfigTypeConflictException('Neutral typed configuration conflict'); }
			return $loaded ? ($value ?? 'yes') : 'yes';
		});
		$config->method('getValueBool')->with('dav', 'sendInvitations', true)->willReturnCallback(static function () use ($value, &$loaded): bool { return $loaded && is_bool($value) ? $value : true; });
		// Both drafts and CalendarDraftApproval share this Scheduling instance in the fixture.
		$scheduling = (new \ReflectionProperty(CreateEventDraft::class, 'scheduling'))->getValue($this->writeHandlers['calendar_create_event']);
		(new \ReflectionProperty(Scheduling::class, 'config'))->setValue($scheduling, $config);
		$this->store->addObject(1, 'event.ics', self::ics("UID:event\nSUMMARY:Before\nDTSTART:20261201T090000Z\nDTEND:20261201T100000Z"));
		$args = $tool === 'calendar_create_event'
			? ['calendar' => self::PERSONAL, 'summary' => 'Neutral event', 'start' => '2026-12-02', 'end' => '2026-12-03', 'allDay' => true]
			: ['calendar' => self::PERSONAL, 'uid' => 'event', 'description' => 'Neutral description'];
		$result = $this->registry->call($tool, $args, 'alice');
		self::assertArrayNotHasKey('isError', $result, $result['content'][0]['text'] ?? '');
		$plan = self::json($result);
		self::assertTrue($plan['requiresConfirmation']);
		self::assertSame($enabled, $plan['scheduling']['imipEnabled']);
		$this->assertNoWrites();
	}
}
