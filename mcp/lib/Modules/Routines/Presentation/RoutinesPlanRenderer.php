<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** The readable Markdown of a routine plan; the complete plan of each step stays in structuredContent. */
final class RoutinesPlanRenderer {
	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $plan the plan of that tool
	 * @return string|null the Markdown body, or null for the generic one
	 */
	public function render(string $tool, array $plan): ?string {
		if ($tool === 'routines_create_mail_card') {
			return (new MailCardPlanRenderer())->render($plan);
		}
		if ($tool !== 'routines_create_meeting') {
			return null;
		}
		try {
			[$room, $agenda, $event] = array_column($plan['steps'], 'plan');
			$zone = (string)($event['timezone'] ?? 'UTC');
			$names = array_map(static fn (array $guest): string => PlanText::inline((string)$guest['displayName']), $room['draft']['participants']);
			$lines = [Translator::t('Create the meeting %s in three steps, in this order:', [PlanText::inline((string)$room['draft']['name'])]),
				'1. ' . Translator::t('A new private Talk room with %s.', [implode(', ', $names)]),
				'2. ' . Translator::t('The agenda, posted as the first message of the room: %s', [PlanText::inline((string)$agenda['draft']['message'], 400)]),
				'3. ' . Translator::t('An event in the calendar %s from %s to %s (%s), with the participants as guests and the room link as its location.', [
					PlanText::inline((string)$event['calendar']['name']), self::local((string)$event['after']['start'], $zone),
					self::local((string)$event['after']['end'], $zone), PlanText::inline($zone)]),
				''];
			$warnings = array_map(static fn (array $warning): string => (string)($warning['message'] ?? ''), (array)($event['warnings'] ?? []));
			foreach ([(string)($event['scheduling']['message'] ?? ''), ...$warnings, ...(array)($plan['notices'] ?? [])] as $notice) {
				if ($notice !== '') {
					$lines[] = '- ' . PlanText::inline($notice, 400);
				}
			}
		} catch (\Throwable) {
			return null;
		}
		return implode("\n", $lines);
	}

	/**
	 * @param string $instant ISO 8601 instant of the event plan
	 * @param string $zone time zone of the account, which the event plan names
	 * @return string the instant in that zone, without the Intl formatter so the text is the same on every runtime
	 * @throws \Exception when the instant or the zone cannot be read; the caller then falls back to the generic plan
	 */
	private static function local(string $instant, string $zone): string {
		return PlanText::inline((new \DateTimeImmutable($instant))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i'));
	}
}
