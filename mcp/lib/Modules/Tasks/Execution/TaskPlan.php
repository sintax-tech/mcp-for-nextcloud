<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Execution;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Platform\Dav\Collections\Collection as Calendar;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Sabre\VObject\Component\VCalendar;
final class TaskPlan
{
    public function __construct(
        private SharedGuard $guard,
        private \OCA\Mcp\Modules\Tasks\Presentation\TaskOutput $output,
        private ?UserTimezone $zones = null,
    )
    {
    }
    public function build(
        string $name,
        Calendar $calendar,
        ?array $row,
        ?array $before,
        ?VCalendar $after,
        string $userId,
        bool $creating = false,
        bool $deleting = false,
    ): array
    {
        $notice = $this->guard->forCollection($calendar, $userId);
        $shared = $notice === null ? [] : [$notice];
        $plan = [
            'requiresConfirmation' => true,
            'message' => Translator::t('Nothing was changed. Show this plan and ask for explicit confirmation.'),
            'action' => $name,
            'calendar' => (array) $calendar,
            'etag' => $row['etag'] ?? null,
            'before' => $before,
            // A new task has no uid the person could use yet: the confirmed call generates a random one and returns it.
            // The iCalendar blob carries that provisional uid too, so a creation plan leaves the blob out.
            'after' => $after === null ? null : array_diff_key($this->output->item($userId, $after), $creating ? ['uid' => 1, 'icalendar' => 1] : []),
            'shared' => $shared,
            'recoverable' => $deleting,
            'consequence' => $deleting ? Translator::t('The task will be moved to the calendar trash.') : null,
            // The timezone travels with the plan, so whoever renders it later reads the dates of the
            // account that asked, without this module having to remember who that was.
            'timezone' => $this->zones?->forUser($userId)->getName(),
        ];
        return $plan;
    }
    public function assertAcknowledged(array $plan, array $arguments): void
    {
        if ($plan['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
            throw new ToolFailure(Translator::t('Confirm the change to the shared collection with confirm_shared=true.'));
        }
    }
}
