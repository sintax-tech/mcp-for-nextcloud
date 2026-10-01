<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Scheduling;

use DateTimeImmutable;
use OCP\Calendar\IManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Asks Nextcloud whether the attendees of an event are free in a time range.
 *
 * Uses the official scheduling API, which only reports on accounts of this instance and never
 * exposes the content of an appointment: only the free/busy verdict per attendee comes back.
 * Neither e-mail addresses nor appointment content are ever logged: a failure records the exception class only.
 */
final class AvailabilityCheck {
    /**
     * @param IManager $calendarManager core calendar manager
     * @param IUserManager $users resolves the organizer account
     * @param LoggerInterface $logger receives the failure warning
     */
    public function __construct(
        private IManager $calendarManager,
        private IUserManager $users,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param string $organizerUid acting user, from whose perspective the check runs; never reported
     * @param DateTimeImmutable $start start of the range
     * @param DateTimeImmutable $end end of the range
     * @param list<array{uid:string, email:string, displayName:string}> $attendees as returned by AttendeeResolver::resolve()
     * @return array{busy: list<array{uid:string, displayName:string}>, unverifiable: list<array{uid:string, displayName:string}>, failed: bool}
     *   failed is true when the API threw or the organizer does not exist; busy and unverifiable are then empty
     */
    public function check(string $organizerUid, DateTimeImmutable $start, DateTimeImmutable $end, array $attendees): array {
        $empty = ['busy' => [], 'unverifiable' => [], 'failed' => false];
        if ($attendees === []) {
            return $empty;
        }
        $failed = ['busy' => [], 'unverifiable' => [], 'failed' => true];

        $organizer = $this->users->get($organizerUid);
        if ($organizer === null) {
            $this->logger->warning('Availability check skipped: organizer account not found');
            return $failed;
        }

        $emails = array_map(fn (array $a): string => self::normalise($a['email']), $attendees);
        try {
            $results = $this->calendarManager->checkAvailability($start, $end, $organizer, $emails);
        } catch (\Throwable $e) {
            // Only the class is logged: a core message can carry appointment text or addresses.
            $this->logger->warning('Availability check failed: ' . $e::class);
            return $failed;
        }

        $verdict = [];
        foreach ($results as $result) {
            $verdict[self::normalise($result->getAttendeeEmail())] = $result->isAvailable();
        }

        $busy = [];
        $unverifiable = [];
        foreach ($attendees as $attendee) {
            $entry = ['uid' => $attendee['uid'], 'displayName' => $attendee['displayName']];
            $key = self::normalise($attendee['email']);
            if (!array_key_exists($key, $verdict)) {
                $unverifiable[] = $entry;
            } elseif ($verdict[$key] === false) {
                $busy[] = $entry;
            }
        }
        return ['busy' => $busy, 'unverifiable' => $unverifiable, 'failed' => false];
    }

    /**
     * @param string $email address, possibly prefixed with `mailto:`
     * @return string lower-cased address without the prefix
     */
    private static function normalise(string $email): string {
        $email = trim($email);
        if (stripos($email, 'mailto:') === 0) {
            $email = substr($email, 7);
        }
        return strtolower($email);
    }
}
