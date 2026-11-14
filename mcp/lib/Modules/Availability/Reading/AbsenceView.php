<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Reading;

use DateTimeImmutable;
use DateTimeZone;

/** Projects the stored absence into the tool's answer; no native object reaches the client. */
final class AbsenceView {
	/**
	 * @param array{first_day:string,last_day:string,status:string,message:string,replacement_user_id:?string,replacement_display_name:?string,timezone:string,start:int,end:int,in_effect:bool,now:int}|null $absence gateway record
	 * @return array<string,mixed> configured flag and, when set, the absence with its state at read time
	 */
	public function project(?array $absence): array {
		if ($absence === null) return ['configured' => false, 'absence' => null];
		$zone = new DateTimeZone($absence['timezone']);
		$state = $absence['in_effect'] ? 'in_effect' : ($absence['start'] > $absence['now'] ? 'upcoming' : 'ended');
		$replacement = $absence['replacement_user_id'] === null ? null
			: ['user_id' => $absence['replacement_user_id'], 'display_name' => $absence['replacement_display_name']];
		return ['configured' => true, 'absence' => [
			'state' => $state, 'first_day' => $absence['first_day'], 'last_day' => $absence['last_day'], 'timezone' => $absence['timezone'],
			'starts_at' => $this->instant($absence['start'], $zone), 'ends_at' => $this->instant($absence['end'], $zone),
			'status' => $absence['status'], 'message' => $absence['message'], 'replacement' => $replacement,
		]];
	}
	/**
	 * @param int $timestamp native Unix time
	 * @param DateTimeZone $zone the zone the native conversion used
	 * @return string ISO 8601 with offset
	 */
	private function instant(int $timestamp, DateTimeZone $zone): string {
		return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format(DATE_ATOM);
	}
}
