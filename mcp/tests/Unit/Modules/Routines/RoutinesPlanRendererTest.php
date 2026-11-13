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

/** The routine plans are text the person can read, and every name, agenda, email and notice in them stays inert. */
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

	/** @return void every write of this module renders its own plan */
	public function testEveryWriteHasAReadablePlan(): void {
		$this->assertEveryWriteToolHasAReadablePlan(new RoutinesModule($this->createMock(ContainerInterface::class)), [
			'routines_create_meeting' => self::plan(),
			'routines_create_mail_card' => MailCardPlanSample::plan(),
		]);
	}

	/** @return void the email, both steps, the exact card text and the readers are in the text, and the email renders inert */
	public function testTheMailCardPlanNamesBothStepsTheTextAndTheReaders(): void {
		$text = (new RoutinesModule($this->createMock(ContainerInterface::class)))->renderPlan('routines_create_mail_card', MailCardPlanSample::plan(
			['readable' => true, 'owner' => 'alice', 'ownerDisplayName' => 'Alice Silva', 'entries' => [['type' => 'group', 'id' => 'vendas', 'displayName' => 'Vendas']]]));

		self::assertIsString($text);
		self::assertStringContainsString('/Documentos/pedido.pdf', $text);
		self::assertStringContainsString('Pedido 42', $text);
		self::assertStringContainsString('Vendas (group)', $text);
		self::assertStringNotContainsString('](https://evil.example)', $text, 'a link written in the email never renders');
	}

	/** @return void a board whose readers could not be read says so, never that it is private */
	public function testAnUnreadableBoardIsNamedAsSuch(): void {
		$text = (new RoutinesModule($this->createMock(ContainerInterface::class)))->renderPlan('routines_create_mail_card', MailCardPlanSample::plan(['readable' => false]));

		self::assertStringContainsString('could not be shown', (string)$text);
		self::assertStringNotContainsString('only its owner', (string)$text);
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
