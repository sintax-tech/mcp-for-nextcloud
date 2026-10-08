<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

/**
 * The summary of logs_analyze over the entries of one window that passed the filters: totals by level, app and user,
 * the most frequent message signatures, the most requested URL paths and user agents, and a histogram by hour.
 *
 * Everything shown goes through {@see LogRedactor} first, so a summary carries no more than the entries would.
 */
final class LogAnalyzer {
    /** Signatures listed, the most frequent first. */
    public const TOP_SIGNATURES = 20;
    /** URL paths and user agents listed, the most frequent first. */
    public const TOP_VALUES = 10;
    /** Apps and users listed in the totals. */
    public const TOP_TOTALS = 50;
    /** Longest signature, in characters. */
    public const SIGNATURE_LIMIT = 200;
    /** Level names, highest first, by the number the core writes. */
    public const LEVELS = [4 => 'fatal', 3 => 'error', 2 => 'warning', 1 => 'info', 0 => 'debug'];
    /**
     * Paths that name a file of somebody, under any webroot ("/nextcloud/remote.php/…"); what is kept before the fold:
     * - the account of a WebDAV path (files, uploads, trashbin, versions, comments, systemtags-relations);
     * - nothing after the legacy /webdav endpoint, of remote.php or public.php;
     * - nothing of a public share: its token opens the share, in /public.php/dav/files/<token> and in /s/<token>.
     */
    private const FOLDED_PATHS = [
        '#^((?:/[^/]+)*?/remote\.php/dav/(?:files|uploads|trashbin|versions|comments|systemtags-relations)/[^/]+)(?:/.*)?$#',
        '#^((?:/[^/]+)*?/(?:remote|public)\.php/webdav)(?:/.*)?$#',
        '#^((?:/[^/]+)*?/public\.php/dav/files)(?:/.*)?$#',
        '#^((?:/[^/]+)*?/s)/[^/]+(?:/.*)?$#',
    ];

    public function __construct(private LogTime $time) {}

    /**
     * What varies between two occurrences of the same problem, folded into '#': quoted text, paths and URLs, UUIDs,
     * hashes and numbers.
     *
     * @param string $message the message of an entry
     * @return string its signature, at most SIGNATURE_LIMIT characters
     */
    public static function signature(string $message): string {
        $text = LogRedactor::text($message, LogRedactor::MESSAGE_LIMIT);
        $text = preg_replace(['/"[^"]*"/u', '/“[^”]*”/u', "/(?<!\\w)'[^']*'/u"], '#', $text) ?? '';
        $text = preg_replace('#\S*/\S*#u', '#', $text) ?? '';
        $text = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '#', $text) ?? '';
        $text = preg_replace('/\b(?=[0-9a-f]*\d)[0-9a-f]{8,}\b/i', '#', $text) ?? '';
        $text = preg_replace('/\d+/', '#', $text) ?? '';
        $text = preg_replace(['/#+/', '/\s+/u'], ['#', ' '], $text) ?? '';
        return mb_substr(trim($text), 0, self::SIGNATURE_LIMIT);
    }

    /**
     * @param list<\stdClass> $entries entries that passed the filters, newest first
     * @return array<string, mixed> matched, byLevel, byApp, byUser, signatures, distinctSignatures, topUrls,
     *     topUserAgents, hourly and hourlyUnparsed
     */
    public function analyze(array $entries): array {
        $levels = $apps = $users = $urls = $agents = $signatures = $hours = [];
        $unparsed = 0;
        foreach ($entries as $entry) {
            $level = self::LEVELS[(int)$entry->level] ?? (string)$entry->level;
            $levels[$level] = ($levels[$level] ?? 0) + 1;
            $app = self::field($entry, 'app', 100);
            $apps[$app] = ($apps[$app] ?? 0) + 1;
            $user = self::field($entry, 'user', 100);
            $users[$user] = ($users[$user] ?? 0) + 1;
            $url = self::path(self::field($entry, 'url', 2000));
            $urls[$url] = ($urls[$url] ?? 0) + 1;
            $agent = self::field($entry, 'userAgent', 300);
            $agents[$agent] = ($agents[$agent] ?? 0) + 1;

            $time = is_string($entry->time ?? null) ? LogRedactor::text($entry->time, 64) : '';
            $message = is_string($entry->message ?? null) ? $entry->message : '';
            $signature = self::signature($message);
            $key = $level . "\0" . $app . "\0" . $signature;
            // Newest first: the first occurrence read is the last one written, and each older one moves `first` back.
            $signatures[$key] ??= ['signature' => $signature, 'level' => $level, 'app' => $app, 'count' => 0, 'first' => $time, 'last' => $time,
                'example' => LogRedactor::text($message, LogRedactor::MESSAGE_LIMIT)];
            $signatures[$key]['count']++;
            $signatures[$key]['first'] = $time;

            $at = $this->time->parse($entry->time ?? null);
            if ($at === null) {
                $unparsed++;
                continue;
            }
            $hour = $at->format('Y-m-d\TH:00P');
            $hours[$hour] ??= ['hour' => $hour, 'count' => 0, 'start' => $at->setTime((int)$at->format('H'), 0)->getTimestamp()];
            $hours[$hour]['count']++;
        }
        $signatures = array_values($signatures);
        usort($signatures, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['signature'], $b['signature']));
        usort($hours, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        // Highest level first; a level the core does not define comes last, under its own number.
        $byLevel = [];
        foreach (self::LEVELS as $name) {
            if (isset($levels[$name])) {
                $byLevel[$name] = $levels[$name];
            }
        }
        return [
            'matched' => count($entries),
            'byLevel' => $byLevel + $levels,
            'byApp' => self::top($apps, 'app', self::TOP_TOTALS),
            'byUser' => self::top($users, 'user', self::TOP_TOTALS),
            'signatures' => array_slice($signatures, 0, self::TOP_SIGNATURES),
            'distinctSignatures' => count($signatures),
            'topUrls' => self::top($urls, 'path', self::TOP_VALUES),
            'topUserAgents' => self::top($agents, 'userAgent', self::TOP_VALUES),
            'hourly' => array_map(static fn (array $hour): array => ['hour' => $hour['hour'], 'count' => $hour['count']], $hours),
            'hourlyUnparsed' => $unparsed,
        ];
    }

    /**
     * The path of a URL without its query, with the file part of a WebDAV path, the token of a public share and every
     * number folded.
     *
     * @param string $url the redacted url field
     * @return string the path to count
     */
    private static function path(string $url): string {
        $path = explode('?', $url, 2)[0];
        foreach (self::FOLDED_PATHS as $pattern) {
            if (preg_match($pattern, $path, $match) === 1) {
                return mb_substr($match[1], 0, self::SIGNATURE_LIMIT) . '/…';
            }
        }
        return mb_substr(preg_replace(['/\d+/', '/#+/'], '#', $path) ?? '', 0, self::SIGNATURE_LIMIT);
    }

    /** @return string a text field of the entry, redacted; '' when it is missing */
    private static function field(\stdClass $entry, string $name, int $limit): string {
        $value = $entry->{$name} ?? '';
        // The user agent keeps the versions after a slash, as in logs_list.
        return is_scalar($value) ? LogRedactor::text((string)$value, $limit, $name === 'userAgent') : '';
    }

    /**
     * @param array<string, int> $counts count by value
     * @param string $label name of the value in each row
     * @param int $limit rows kept
     * @return list<array<string, int|string>> the most frequent first, ties by value
     */
    private static function top(array $counts, string $label, int $limit): array {
        $rows = [];
        foreach ($counts as $value => $count) {
            $rows[] = [$label => (string)$value, 'count' => $count];
        }
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a[$label], $b[$label]));
        return array_slice($rows, 0, $limit);
    }
}
