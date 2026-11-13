<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Editing;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Availability\Integration\AbsenceGateway;
use OCA\Mcp\Modules\Availability\Presentation\AvailabilityMessages;
use OCA\Mcp\Modules\Availability\Reading\AbsenceView;
use OCP\IUserManager;
use RuntimeException;

/**
 * The plan of availability_set_absence, read now: the actor's stored absence (the OO-1 read path), the new values,
 * the replacement resolved like the native POST (an existing user and its current display name) and kept when the call
 * does not mention it, and the native effects. The confirmed call rebuilds it and writes only when it is still the
 * approved one.
 */
final class SetAbsencePlan {
	/** Tool the plan belongs to. */
	public const TOOL = 'availability_set_absence';
	/**
	 * @param AbsenceGateway $native stored absence, feature switch, time zone and clock
	 * @param AbsenceInput $input strict arguments
	 * @param AbsenceView $view projection of the stored absence
	 * @param IUserManager $users replacement lookup
	 * @param PlanState $states the instance's HMAC of what the plan showed
	 */
	public function __construct(private AbsenceGateway $native, private AbsenceInput $input, private AbsenceView $view,
		private IUserManager $users, private PlanState $states) {}
	/**
	 * @param string $uid authenticated actor; the only account ever planned for
	 * @param array<string,mixed> $arguments validated arguments
	 * @return array{plan:array<string,mixed>,write:array{first_day:string,last_day:string,status:string,message:string,replacement_user_id:?string,replacement_display_name:?string},mode:string} the signed plan and the exact native values
	 * @throws ToolFailure for invalid values, the feature turned off, an unknown replacement or an unreadable absence
	 */
	public function build(string $uid, array $arguments): array {
		$values = $this->input->parse($arguments);
		try {
			if (!$this->native->enabled()) throw new ToolFailure(AvailabilityMessages::turnedOff());
			$stored = $this->native->read($uid);
			$zone = $this->native->timezone($uid);
			$now = $this->native->now();
		} catch (RuntimeException $e) {
			throw $e instanceof ToolFailure ? $e : new ToolFailure(AvailabilityMessages::unavailable());
		}
		[$replacementId, $replacementName] = $this->replacement($values['replacement'], $stored);
		$write = ['first_day' => $values['first_day'], 'last_day' => $values['last_day'], 'status' => $values['status'], 'message' => $values['message'],
			'replacement_user_id' => $replacementId, 'replacement_display_name' => $replacementName];
		[$start, $end] = $this->instants($write['first_day'], $write['last_day'], $zone);
		$mode = $stored === null ? 'create' : 'update';
		$notices = [$mode === 'create' ? AvailabilityMessages::calendarCreated() : AvailabilityMessages::calendarUpdated()];
		if ($end->getTimestamp() > $now) $notices[] = AvailabilityMessages::jobsApply();
		if ($stored !== null && (($stored['start'] > $now && $start->getTimestamp() <= $now) || ($stored['end'] > $now && $end->getTimestamp() <= $now))) $notices[] = AvailabilityMessages::staleJob();
		if (($stored['replacement_user_id'] ?? null) !== null && ($stored['replacement_user_id'] ?? '') !== '' && $replacementId === null) $notices[] = AvailabilityMessages::replacementRemoved();
		$notices[] = AvailabilityMessages::noClear();
		$plan = ['action' => self::TOOL, 'mode' => $mode, 'before' => $stored === null ? null : $this->view->project($stored)['absence'],
			'after' => ['first_day' => $write['first_day'], 'last_day' => $write['last_day'], 'timezone' => $zone,
				'starts_at' => $start->format(DATE_ATOM), 'ends_at' => $end->format(DATE_ATOM), 'status' => $write['status'], 'message' => $write['message'],
				'replacement' => $replacementId === null ? null : ['user_id' => $replacementId, 'display_name' => $replacementName]],
			'recoverable' => true, 'notices' => $notices, 'message' => AvailabilityMessages::planNotice()];
		$shown = ['write' => $write, 'zone' => $zone, 'stored' => $stored === null ? null : array_diff_key($stored, ['in_effect' => 0, 'now' => 0])];
		return ['plan' => $plan + [PlanState::ARGUMENT => $this->states->of(self::TOOL, $uid, $shown)], 'write' => $write, 'mode' => $mode];
	}
	/**
	 * @param array{0:string,1:?string} $request ['keep', null] or ['set', ?uid]
	 * @param array<string,mixed>|null $stored current absence
	 * @return array{0:?string,1:?string} replacement user ID and its current display name, or none
	 * @throws ToolFailure for an unknown user, a stored replacement that no longer exists or an unstorable name
	 */
	private function replacement(array $request, ?array $stored): array {
		$id = $request[0] === 'set' ? $request[1] : ($stored['replacement_user_id'] ?? null);
		if ($id === null || $id === '') return [null, null];
		$user = $this->users->get($id);
		if ($user === null || $user->getUID() !== $id) {
			throw new ToolFailure($request[0] === 'set' ? AvailabilityMessages::replacementNotFound() : AvailabilityMessages::replacementGone());
		}
		$name = $user->getDisplayName();
		if (mb_strlen($name) > AbsenceInput::NAME_MAX) throw new ToolFailure(AvailabilityMessages::replacementNameTooLong());
		return [$id, $name];
	}
	/**
	 * Absence::toOutOufOfficeData (Absence.php:72-75): the first day at 00:00 and the last day at 23:59 in the zone.
	 *
	 * @param string $first first day
	 * @param string $last last day
	 * @param string $zone actor's zone
	 * @return array{0:DateTimeImmutable,1:DateTimeImmutable} start and end
	 * @throws ToolFailure when the native zone is not a valid zone
	 */
	private function instants(string $first, string $last, string $zone): array {
		try {
			$tz = new DateTimeZone($zone);
			return [new DateTimeImmutable($first, $tz), (new DateTimeImmutable($last, $tz))->setTime(23, 59)];
		} catch (\Exception) {
			throw new ToolFailure(AvailabilityMessages::unavailable());
		}
	}
}
