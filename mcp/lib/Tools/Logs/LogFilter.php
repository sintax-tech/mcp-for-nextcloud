<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

use InvalidArgumentException;

/**
 * The filters of logs_list and logs_analyze, validated once and applied to each entry read from the end of the log.
 *
 * Level, app, user and request id are exact; `contains` is a case-insensitive substring of the message, never a
 * pattern; `since` and `until` are instants in ISO 8601 compared with the time of the entry as {@see LogTime} reads it.
 */
final class LogFilter {
    /** The entry passes every filter. */
    public const MATCH = 'match';
    /** The entry fails a filter. */
    public const SKIP = 'skip';
    /** A date filter is set and the time of the entry cannot be read: left out and counted. */
    public const UNPARSED = 'unparsed';
    /** Highest log level, fatal. */
    public const MAX_LEVEL = 4;
    /** Longest text accepted by `contains`. */
    public const MAX_CONTAINS = 200;
    /** ISO 8601 date, optionally with a time and an offset. */
    private const ISO_8601 = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/D';

    /**
     * @param array<string, int|string> $described the filters as the caller wrote them, in a fixed order
     */
    private function __construct(
        private LogTime $time,
        private ?int $minLevel,
        private ?string $app,
        private ?string $user,
        private ?\DateTimeImmutable $since,
        private ?\DateTimeImmutable $until,
        private ?string $contains,
        private ?string $reqId,
        private array $described,
    ) {}

    /**
     * @param array<string, mixed> $arguments validated tool arguments
     * @param LogTime $time how the entries write their time
     * @return self the filter
     * @throws InvalidArgumentException for a level out of range, an empty text or a date that is not ISO 8601
     */
    public static function fromArguments(array $arguments, LogTime $time): self {
        $minLevel = isset($arguments['min_level']) ? (int)$arguments['min_level'] : null;
        if ($minLevel !== null && ($minLevel < 0 || $minLevel > self::MAX_LEVEL)) {
            throw new InvalidArgumentException('min_level must be between 0 and 4');
        }
        $text = static fn (string $key): ?string => isset($arguments[$key]) ? (string)$arguments[$key] : null;
        $contains = $text('contains');
        if ($contains !== null && ($contains === '' || mb_strlen($contains) > self::MAX_CONTAINS)) {
            throw new InvalidArgumentException('contains must have 1 to 200 characters');
        }
        $since = self::instant($text('since'));
        $until = self::instant($text('until'));
        if ($since !== null && $until !== null && $since > $until) {
            throw new InvalidArgumentException('since must not be after until');
        }
        $described = array_filter([
            'min_level' => $minLevel, 'app' => $text('app'), 'user' => $text('user'), 'since' => $text('since'),
            'until' => $text('until'), 'contains' => $contains, 'req_id' => $text('req_id'),
        ], static fn ($value): bool => $value !== null);
        return new self($time, $minLevel, $text('app'), $text('user'), $since, $until, $contains, $text('req_id'), $described);
    }

    /** @return bool whether no filter is set, so every entry matches */
    public function isEmpty(): bool {
        return $this->described === [];
    }

    /** @return array<string, int|string> the filters set, as the caller wrote them, for the answer and the audit */
    public function describe(): array {
        return $this->described;
    }

    /** @return bool whether a date filter is set */
    public function hasDates(): bool {
        return $this->since !== null || $this->until !== null;
    }

    /**
     * @param \stdClass $entry one decoded entry of the log
     * @return string MATCH, SKIP or UNPARSED
     */
    public function test(\stdClass $entry): string {
        if ($this->hasDates()) {
            $at = $this->time->parse($entry->time ?? null);
            if ($at === null) {
                return self::UNPARSED;
            }
            // The file is not in time order: the core stamps an entry with microtime() when it builds it, and concurrent
            // workers append theirs in any order, so an entry before `since` is skipped and the search goes on.
            if (($this->since !== null && $at < $this->since) || ($this->until !== null && $at > $this->until)) {
                return self::SKIP;
            }
        }
        $passes = ($this->minLevel === null || (is_int($entry->level ?? null) && $entry->level >= $this->minLevel))
            && ($this->app === null || ($entry->app ?? null) === $this->app)
            && ($this->user === null || ($entry->user ?? null) === $this->user)
            && ($this->reqId === null || ($entry->reqId ?? null) === $this->reqId)
            && ($this->contains === null || (is_string($entry->message ?? null) && mb_stripos($entry->message, $this->contains) !== false));
        return $passes ? self::MATCH : self::SKIP;
    }

    /**
     * @param string|null $value an ISO 8601 date, with or without time and offset; without an offset, UTC
     * @return \DateTimeImmutable|null the instant, null when the filter is not set
     * @throws InvalidArgumentException for anything else, an impossible date included
     */
    private static function instant(?string $value): ?\DateTimeImmutable {
        if ($value === null) {
            return null;
        }
        if (preg_match(self::ISO_8601, $value, $parts) !== 1 || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
            || (int)($parts[4] ?? 0) > 23 || (int)($parts[5] ?? 0) > 59 || (int)($parts[6] ?? 0) > 59) {
            throw new InvalidArgumentException('Dates must be ISO 8601, for example 2026-10-07T18:00:00-03:00');
        }
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
