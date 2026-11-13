<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Modules\Routines\RoutinesModule;
use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** The meeting plan is text the person can read, and every name, agenda and notice in it stays inert. */
final class RoutinesPlanRendererTest extends TestCase {
	use AssertsReadablePlans;

	/** @return array<string, mixed> the plan of a meeting as CreateMeetingPreview builds it */
	private static function plan(): array {
		return [
			'action' => 'routines_create_meeting',
			'message' => 'Nothing was created.',
			'steps' => [
				['step' => 'room', 'tool' => 'talk_create_group', 'plan' => ['action' => 'talk_create_group',
					'draft' => ['name' => 'Kickoff', 'participants' => [['id' => 'bob', 'displayName' => 'Bob Souza']]]]],
				['step' => 'agenda', 'tool' => 'talk_reply', 'plan' => ['action' => 'talk_reply', 'conversation' => null,
					'draft' => ['message' => "1. Scope\n2. Dates"]]],
				['step' => 'event', 'tool' => 'calendar_create_event', 'plan' => [
					'calendar' => ['name' => 'Personal'],
					'after' => ['start' => '2026-03-12T17:00:00+00:00', 'end' => '2026-03-12T18:00:00+00:00'],
					'timezone' => 'America/Sao_Paulo',
					'scheduling' => ['message' => 'No invitations will be scheduled.'],
					'warnings' => [['type' => 'calendarNotShared', 'message' => 'The calendar Personal is not shared with Bob Souza.']],
				], 'room_link' => ['field' => 'location', 'value' => null]],
			],
			'notices' => ['The link of the new room becomes the location of the event once the room exists.'],
		];
	}

	/** @return void the one write of this module renders its own plan */
	public function testEveryWriteHasAReadablePlan(): void {
		$this->assertEveryWriteToolHasAReadablePlan(new RoutinesModule($this->createMock(ContainerInterface::class)), [
			'routines_create_meeting' => self::plan(),
		]);
	}

	/** @return void the three steps, the time in the account zone and every warning are in the text */
	public function testThePlanNamesTheThreeStepsInTheAccountZone(): void {
		$text = (new RoutinesModule($this->createMock(ContainerInterface::class)))->renderPlan('routines_create_meeting', self::plan());

		self::assertIsString($text);
		self::assertStringContainsString('Bob Souza', $text);
		self::assertStringContainsString('2026-03-12 14:00', $text);
		self::assertStringContainsString('America/Sao\_Paulo', $text);
		self::assertStringContainsString('not shared with Bob Souza', $text);
		self::assertStringContainsString('location of the event', $text);
	}
}
