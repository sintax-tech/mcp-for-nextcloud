<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Calendar\CalendarPlanRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CalendarPlanRenderer: verifies human-readable Markdown rendering
 * for calendar operations across languages, timezones, all-day events, and error cases.
 */
final class CalendarPlanRendererTest extends TestCase {
    private CalendarPlanRenderer $renderer;

    protected function setUp(): void {
        parent::setUp();
        $this->renderer = new CalendarPlanRenderer();
    }

    protected function tearDown(): void {
        Translator::reset();
        parent::tearDown();
    }

    public function testCreateEventPtBr(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_create_event',
            'calendar' => ['name' => 'Tecnologia Dalcomad', 'path' => '/calendars/user/tech'],
            'after' => [
                'summary' => 'Reunião vazamento',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
                'location' => 'Sala 1',
                'description' => 'Alinhamento sobre vazamento',
                'attendees' => ['Pedro (pedro)'],
            ],
            'participants' => [
                'proposed' => ['Pedro (pedro)'],
            ],
            'scheduling' => [
                'message' => 'Após confirmação, o Nextcloud poderá agendar convites.',
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_create_event', $plan);
        $this->assertNotNull($markdown);

        $expected = "Criar evento em *Tecnologia Dalcomad*: **Reunião vazamento**\n"
            . "- Quando: qui, 1 de out. de 2026, 14:00–15:00 (America/Sao_Paulo)\n"
            . "- Local: Sala 1\n"
            . "- Descrição: Alinhamento sobre vazamento\n"
            . "- Participantes: Pedro (pedro)\n"
            . "- Convites: Após confirmação, o Nextcloud poderá agendar convites.";

        $this->assertSame($expected, $markdown);
    }

    public function testCreateEventEn(): void {
        Translator::use(new JsonL10n('en'));

        $plan = [
            'action' => 'calendar_create_event',
            'calendar' => ['name' => 'Dalcomad Tech'],
            'after' => [
                'summary' => 'Leak meeting',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
                'location' => 'Room 1',
                'attendees' => ['Pedro (pedro)'],
            ],
            'participants' => [
                'proposed' => ['Pedro (pedro)'],
            ],
            'scheduling' => [
                'message' => 'After approval, Nextcloud may schedule invitations.',
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_create_event', $plan);
        $this->assertNotNull($markdown);

        $this->assertStringContainsString('Create event in *Dalcomad Tech*: **Leak meeting**', $markdown);
        $this->assertStringContainsString('- When: Thu, Oct 1, 2026', $markdown);
        $this->assertStringContainsString('(America/Sao_Paulo)', $markdown);
        $this->assertStringContainsString('- Location: Room 1', $markdown);
        $this->assertStringContainsString('- Participants: Pedro (pedro)', $markdown);
        $this->assertStringContainsString('- Invitations: After approval, Nextcloud may schedule invitations.', $markdown);
    }

    public function testCreateEventWithoutOptionalFields(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_create_event',
            'calendar' => ['name' => 'Tecnologia Dalcomad'],
            'after' => [
                'summary' => 'Sem detalhes',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_create_event', $plan);
        $expected = "Criar evento em *Tecnologia Dalcomad*: **Sem detalhes**\n"
            . "- Quando: qui, 1 de out. de 2026, 14:00–15:00 (America/Sao_Paulo)";

        $this->assertSame($expected, $markdown);
    }

    public function testCreateEventWithNoTitleFallsBack(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_create_event',
            'calendar' => ['name' => 'Agenda'],
            'after' => [
                'summary' => '',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_create_event', $plan);
        $this->assertStringContainsString('Criar evento em *Agenda*: **(sem título)**', (string)$markdown);
    }

    public function testUpdateEventPtBrOnlyChangedFields(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_update_event',
            'calendar' => ['name' => 'Tecnologia Dalcomad'],
            'before' => [
                'summary' => 'Reunião vazamento',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
                'location' => 'Sala 1',
                'description' => 'Descrição antiga',
                'attendees' => ['Pedro (pedro)'],
            ],
            'after' => [
                'summary' => 'Reunião vazamento',
                'start' => '2026-10-02T19:00:00Z',
                'end' => '2026-10-02T20:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
                'location' => 'Sala 2',
                'description' => 'Descrição antiga',
                'attendees' => ['Pedro (pedro)'],
            ],
            'participants' => [
                'current' => ['Pedro (pedro)'],
                'proposed' => ['Pedro (pedro)'],
            ],
            'scheduling' => [
                'message' => 'Atualizações serão enviadas aos convidados.',
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_update_event', $plan);
        $this->assertNotNull($markdown);

        $expected = "Atualizar evento em *Tecnologia Dalcomad*: **Reunião vazamento**\n"
            . "- Quando: qui, 1 de out. de 2026, 14:00–15:00 (America/Sao_Paulo) → sex, 2 de out. de 2026, 16:00–17:00 (America/Sao_Paulo)\n"
            . "- Local: Sala 1 → Sala 2\n"
            . "- Convites: Atualizações serão enviadas aos convidados.";

        $this->assertSame($expected, $markdown);
    }

    public function testUpdateEventAllFieldsChanged(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_update_event',
            'calendar' => ['name' => 'Tecnologia Dalcomad'],
            'before' => [
                'summary' => 'Reunião antiga',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
                'location' => '',
                'description' => 'Desc antiga',
                'attendees' => ['Pedro (pedro)'],
            ],
            'after' => [
                'summary' => 'Reunião nova',
                'start' => '2026-10-02T19:00:00Z',
                'end' => '2026-10-02T20:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
                'location' => 'Sala 1',
                'description' => 'Desc nova',
                'attendees' => ['Pedro (pedro)', 'Maria (maria)'],
            ],
            'participants' => [
                'current' => ['Pedro (pedro)'],
                'proposed' => ['Pedro (pedro)', 'Maria (maria)'],
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_update_event', $plan);
        $this->assertNotNull($markdown);

        $this->assertStringContainsString('Atualizar evento em *Tecnologia Dalcomad*: **Reunião nova**', $markdown);
        $this->assertStringContainsString('- Título: Reunião antiga → Reunião nova', $markdown);
        $this->assertStringContainsString('- Quando: qui, 1 de out. de 2026, 14:00–15:00 (America/Sao_Paulo) → sex, 2 de out. de 2026, 16:00–17:00 (America/Sao_Paulo)', $markdown);
        $this->assertStringContainsString('- Local: (nenhum) → Sala 1', $markdown);
        $this->assertStringContainsString('- Descrição: Desc antiga → Desc nova', $markdown);
        $this->assertStringContainsString('- Participantes: Pedro (pedro) → Pedro (pedro), Maria (maria)', $markdown);
    }

    public function testUpdateEventNoChanges(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_update_event',
            'calendar' => ['name' => 'Agenda'],
            'before' => [
                'summary' => 'Reunião',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
            ],
            'after' => [
                'summary' => 'Reunião',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_update_event', $plan);
        $expected = "Atualizar evento em *Agenda*: **Reunião**\n- Nenhuma alteração";
        $this->assertSame($expected, $markdown);
    }

    public function testMoveEvent(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_move_event',
            'calendar' => ['name' => 'Tecnologia Dalcomad'],
            'destination' => ['name' => 'Pessoal'],
            'after' => [
                'summary' => 'Reunião vazamento',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
            ],
            'scheduling' => [
                'message' => 'Os participantes do evento não são notificados, assim como ao mover no app Calendar.',
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_move_event', $plan);
        $this->assertNotNull($markdown);

        $expected = "Mover evento de *Tecnologia Dalcomad* para *Pessoal*: **Reunião vazamento**\n"
            . "- Quando: qui, 1 de out. de 2026, 14:00–15:00 (America/Sao_Paulo)\n"
            . "- Convites: Os participantes do evento não são notificados, assim como ao mover no app Calendar.";

        $this->assertSame($expected, $markdown);
    }

    public function testTransferEvent(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_transfer_event',
            'calendar' => ['name' => 'Tecnologia Dalcomad'],
            'destination' => ['name' => 'Empresa'],
            'after' => [
                'summary' => 'Alinhamento',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_transfer_event', $plan);
        $expected = "Transferir evento de *Tecnologia Dalcomad* para *Empresa*: **Alinhamento**\n"
            . "- Quando: qui, 1 de out. de 2026, 14:00–15:00 (America/Sao_Paulo)";

        $this->assertSame($expected, $markdown);
    }

    public function testDeleteEvent(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_delete_event',
            'calendar' => ['name' => 'Tecnologia Dalcomad'],
            'before' => [
                'summary' => 'Reunião vazamento',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
            ],
            'consequence' => 'Após confirmação, o evento irá para a lixeira recuperável do Calendar.',
        ];

        $markdown = $this->renderer->renderPlan('calendar_delete_event', $plan);
        $expected = "Excluir evento em *Tecnologia Dalcomad*: **Reunião vazamento**\n"
            . "- Quando: qui, 1 de out. de 2026, 14:00–15:00 (America/Sao_Paulo)\n"
            . "- Consequência: Após confirmação, o evento irá para a lixeira recuperável do Calendar.";

        $this->assertSame($expected, $markdown);
    }

    public function testAllDaySingleDay(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_create_event',
            'calendar' => ['name' => 'Feriados'],
            'after' => [
                'summary' => 'Folga',
                'start' => '2026-10-01',
                'end' => '2026-10-02',
                'allDay' => true,
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_create_event', $plan);
        $this->assertNotNull($markdown);
        $this->assertStringContainsString('- Quando: qui, 1 de out. de 2026', $markdown);
        $this->assertStringNotContainsString(':', explode('- Quando: ', $markdown)[1]);
    }

    public function testAllDayMultiDay(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_create_event',
            'calendar' => ['name' => 'Férias'],
            'after' => [
                'summary' => 'Viagem',
                'start' => '2026-10-01',
                'end' => '2026-10-04',
                'allDay' => true,
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_create_event', $plan);
        $this->assertNotNull($markdown);
        $this->assertStringContainsString('- Quando: qui, 1 de out. de 2026 – sáb, 3 de out. de 2026', $markdown);
    }

    public function testTimezoneConversion(): void {
        Translator::use(new JsonL10n('pt_BR'));

        // 2026-10-01 02:00 UTC is 2026-09-30 23:00 in America/Sao_Paulo (UTC-3)
        // 2026-10-01 03:00 UTC is 2026-10-01 00:00 in America/Sao_Paulo (crosses midnight)
        $plan = [
            'action' => 'calendar_create_event',
            'calendar' => ['name' => 'Plantão'],
            'after' => [
                'summary' => 'Madrugada',
                'start' => '2026-10-01T02:00:00Z',
                'end' => '2026-10-01T03:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_create_event', $plan);
        $this->assertNotNull($markdown);
        $this->assertStringContainsString('qua, 30 de set. de 2026, 23:00 – qui, 1 de out. de 2026, 00:00 (America/Sao_Paulo)', $markdown);
    }

    public function testMissingOrCorruptedKeysNeverThrowsAndReturnsNull(): void {
        // Unknown tool returns null
        $this->assertNull($this->renderer->renderPlan('calendar_unknown_tool', []));

        // Invalid date format returns null
        $this->assertNull($this->renderer->renderPlan('calendar_create_event', [
            'calendar' => ['name' => 'Test'],
            'after' => ['start' => 'not-a-date', 'end' => 'also-not-a-date', 'allDay' => false],
        ]));

        // Inverted dates for allDay return null
        $this->assertNull($this->renderer->renderPlan('calendar_create_event', [
            'calendar' => ['name' => 'Test'],
            'after' => ['start' => '2026-10-05', 'end' => '2026-10-01', 'allDay' => true],
        ]));

        // Inverted dates for timed return null
        $this->assertNull($this->renderer->renderPlan('calendar_create_event', [
            'calendar' => ['name' => 'Test'],
            'after' => ['start' => '2026-10-05T10:00:00Z', 'end' => '2026-10-01T10:00:00Z', 'allDay' => false],
        ]));

        // Missing after on create returns null
        $this->assertNull($this->renderer->renderPlan('calendar_create_event', [
            'calendar' => ['name' => 'Test'],
        ]));

        // Missing before on delete returns null
        $this->assertNull($this->renderer->renderPlan('calendar_delete_event', [
            'calendar' => ['name' => 'Test'],
        ]));

        // Invalid timezone string returns null
        $this->assertNull($this->renderer->renderPlan('calendar_create_event', [
            'calendar' => ['name' => 'Test'],
            'after' => [
                'summary' => 'Test',
                'start' => '2026-10-01T10:00:00Z',
                'end' => '2026-10-01T11:00:00Z',
                'allDay' => false,
                'timeZone' => 'Invalid/Zone/Name',
            ],
        ]));
    }

    public function testUnknownExtraKeysAreIgnored(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_create_event',
            'calendar' => ['name' => 'Tecnologia Dalcomad', 'unexpectedKey' => 'value'],
            'after' => [
                'summary' => 'Evento',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
                'customProp' => 12345,
            ],
            'unknownTopLevel' => true,
        ];

        $markdown = $this->renderer->renderPlan('calendar_create_event', $plan);
        $this->assertNotNull($markdown);
        $this->assertStringContainsString('Criar evento em *Tecnologia Dalcomad*: **Evento**', $markdown);
    }

    public function testParticipantsAsObjectsOrStrings(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $plan = [
            'action' => 'calendar_create_event',
            'calendar' => ['name' => 'Agenda'],
            'after' => [
                'summary' => 'Reunião',
                'start' => '2026-10-01T17:00:00Z',
                'end' => '2026-10-01T18:00:00Z',
                'allDay' => false,
                'timeZone' => 'America/Sao_Paulo',
            ],
            'participants' => [
                'proposed' => [
                    ['name' => 'Alice', 'uid' => 'alice@example.com'],
                    ['name' => 'bob', 'uid' => 'bob'],
                    'Charlie (charlie)',
                ],
            ],
        ];

        $markdown = $this->renderer->renderPlan('calendar_create_event', $plan);
        $this->assertStringContainsString('- Participantes: Alice (alice@example.com), bob, Charlie (charlie)', (string)$markdown);
    }

    public function testParticipantsAreShownByNameAndNeverWithTheMailtoPrefix(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $plan = [
            'action' => 'calendar_create_event',
            'calendar' => ['name' => 'Agenda'],
            'after' => ['summary' => 'Reunião', 'start' => '2026-10-01T17:00:00Z', 'end' => '2026-10-01T18:00:00Z', 'allDay' => false, 'timeZone' => 'America/Sao_Paulo'],
            'participants' => [
                'proposed' => ['mailto:bob@example.invalid', 'mailto:carla@example.invalid', 'MAILTO:dave@example.invalid'],
                'names' => ['bob@example.invalid' => 'Roberto Almeida', 'dave@example.invalid' => 'Dave Lima'],
            ],
        ];

        $markdown = (string)$this->renderer->renderPlan('calendar_create_event', $plan);

        $this->assertStringContainsString('- Participantes: Roberto Almeida, carla@example.invalid, Dave Lima', $markdown);
        $this->assertStringNotContainsStringIgnoringCase('mailto', $markdown);
    }

    public function testUpdateShowsTheParticipantChangeByNameWithoutMailto(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $event = ['summary' => 'Reunião', 'start' => '2026-10-01T17:00:00Z', 'end' => '2026-10-01T18:00:00Z', 'allDay' => false, 'timeZone' => 'America/Sao_Paulo'];
        $plan = [
            'action' => 'calendar_update_event',
            'calendar' => ['name' => 'Agenda'],
            'before' => $event,
            'after' => $event,
            'participants' => [
                'current' => ['mailto:bob@example.invalid'],
                'proposed' => ['mailto:carla@example.invalid'],
                'names' => ['bob@example.invalid' => 'Roberto Almeida', 'carla@example.invalid' => 'Carla Dias'],
            ],
        ];

        $markdown = (string)$this->renderer->renderPlan('calendar_update_event', $plan);

        $this->assertStringContainsString('- Participantes: Roberto Almeida → Carla Dias', $markdown);
        $this->assertStringNotContainsStringIgnoringCase('mailto', $markdown);
    }
}
