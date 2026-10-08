<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

/**
 * Minimizes what a log entry carries before it reaches the model, without losing its diagnostic value.
 *
 * - IP addresses are masked: IPv4 keeps the first two octets (`203.0.x.x`), IPv6 its /48 prefix, in the remoteAddr
 *   field and inside any text; in the user agent an address right after a slash is a version ("Chrome/141.0.0.0") and
 *   stays, since the version of the client is what makes it useful.
 * - Control characters, ANSI escape sequences and invisible or bidirectional marks are removed: a file name or a
 *   message is written by users, and those are the characters that hide or disguise text.
 * - A text is cut at a limit; an exception keeps class, message, code, place and ten frames of class->function and
 *   file:line, never the arguments.
 */
final class LogRedactor {
    /** Frames of a stack trace kept. */
    public const TRACE_FRAMES = 10;
    /** Previous exceptions kept below the one logged: three levels in all. */
    public const PREVIOUS_LEVELS = 2;
    /** Longest exception message, as the longest log message. */
    public const MESSAGE_LIMIT = 2000;
    /** Shown in place of a value that is not an IP address. */
    private const NOT_AN_ADDRESS = 'x';
    /**
     * IPv4 addresses inside a text, not glued to a word or to another number: "1.2.3.4.5" and "v31.0.9.1" are versions,
     * while a full stop, a comma or a colon after the address is punctuation and stays outside the match. %s is where
     * the characters the address may not follow go: a slash too in a user agent, where "Chrome/141.0.0.0" is a version.
     */
    private const IPV4 = '/(?<![\w.%s])\d{1,3}(?:\.\d{1,3}){3}(?!\.?\d)(?!\w)/';
    /**
     * Candidates for an IPv6 address inside a text; filter_var() decides, so a clock time or a MAC address stays. A
     * trailing colon may be punctuation ("Remote IP: 2001:db8::9:"), so the callback tries once without it. Followed by
     * a prefix length ("2001:db8::/32", or an address already masked) it is a network, left as it is.
     */
    private const IPV6 = '/(?<![\w:.%s])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?!\w)(?!\.\w)(?!:[0-9a-f])(?!\/\d)/i';
    /**
     * An IPv6 candidate glued to a label by a colon ("IP:2001:db8::1", "clientAddress:2001:db8::9"), which the pattern
     * above cannot see, since an address never starts right after a colon there.
     */
    private const LABELED_IPV6 = '/(?<![\w:.%s])([a-z_][\w-]*):((?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4})(?!\w)(?!\.\w)(?!:[0-9a-f])(?!\/\d)/i';
    /** ANSI escape sequences (CSI and OSC). */
    private const ANSI = '/\x{1B}(?:\[[0-9;?]*[ -\/]*[@-~]|\][^\x{07}\x{1B}]*(?:\x{07}|\x{1B}\\\\)?)?/u';
    /**
     * Control characters, C1 controls, the soft hyphen, every bidirectional control (ALM, LRM, RLM, the embeddings,
     * overrides and isolates) and the zero-width characters, the Mongolian vowel separator and the BOM included.
     */
    private const INVISIBLE = '/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';
    /** Line breaks, tabs and the Unicode line and paragraph separators: each becomes a space. */
    private const BREAKS = ["\r\n", "\r", "\n", "\t", "\u{2028}", "\u{2029}"];

    private function __construct() {
    }

    /**
     * @param string $address the remoteAddr of an entry
     * @return string the masked address; '' and '--' unchanged; anything that is not an address becomes 'x'
     */
    public static function ip(string $address): string {
        $address = trim($address);
        if ($address === '' || $address === '--') {
            return $address;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $address);
            return $octets[0] . '.' . $octets[1] . '.x.x';
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return self::NOT_AN_ADDRESS;
        }
        // An IPv4-mapped address is the IPv4 one.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $mapped) === 1) {
            return self::ip($mapped[1]);
        }
        $prefix = substr((string)inet_pton($address), 0, 6) . str_repeat("\0", 10);
        return inet_ntop($prefix) . '/48';
    }

    /**
     * A text fit to show: valid UTF-8, no control or invisible characters, addresses masked, cut at the limit.
     *
     * @param string $text a message, a URL, a user agent
     * @param int $limit longest result in characters, before the marker of a cut
     * @param bool $userAgent true for a user agent: an address right after a slash is a version ("Chrome/141.0.0.0")
     *     and stays; any other address is masked as in every text
     * @return string the cleaned text, with '…' when it was cut
     */
    public static function text(string $text, int $limit, bool $userAgent = false): string {
        // Invalid bytes become U+FFFD, so the patterns below, which need valid UTF-8, never fail on them.
        $text = (string)json_decode(json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        $text = preg_replace(self::ANSI, '', $text) ?? '';
        $text = str_replace(self::BREAKS, ' ', $text);
        $text = preg_replace(self::INVISIBLE, '', $text) ?? '';
        $notAfter = $userAgent ? '\/' : '';
        $text = preg_replace_callback(sprintf(self::IPV4, $notAfter), static function (array $m): string {
            $masked = self::ip($m[0]);
            return $masked === self::NOT_AN_ADDRESS ? $m[0] : $masked;
        }, $text) ?? '';
        $text = preg_replace_callback(sprintf(self::IPV6, $notAfter), static function (array $m): string {
            $candidate = $m[0];
            $suffix = '';
            if (!self::isIpv6($candidate) && str_ends_with($candidate, ':') && !str_ends_with($candidate, '::')) {
                $candidate = substr($candidate, 0, -1);
                $suffix = ':';
            }
            return self::isIpv6($candidate) ? self::ip($candidate) . $suffix : $m[0];
        }, $text) ?? '';
        $text = preg_replace_callback(sprintf(self::LABELED_IPV6, $notAfter), static function (array $m): string {
            [$whole, $label, $address] = $m;
            $suffix = '';
            if (str_ends_with($whole, ':') && !str_ends_with($whole, '::') && !self::isIpv6($address)) {
                [$whole, $address, $suffix] = [substr($whole, 0, -1), substr($address, 0, -1), ':'];
            }
            // A label made of hexadecimal digits may be the first group: then the whole run is one address.
            if (self::isIpv6($whole)) {
                return self::ip($whole) . $suffix;
            }
            return self::isIpv6($address) ? $label . ':' . self::ip($address) . $suffix : $m[0];
        }, $text) ?? '';
        $text = trim($text);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }

    /** @return bool whether the whole string is an IPv6 address */
    private static function isIpv6(string $candidate): bool {
        return filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * @param mixed $exception the `exception` field of an entry, as json_decode() gave it
     * @return array<string, mixed>|null class, message, code, place, the first frames and the previous exceptions; null
     *     when the value is not an exception
     */
    public static function exception(mixed $exception): ?array {
        return self::summary(is_object($exception) || is_array($exception) ? json_decode(json_encode($exception), true) : null, self::PREVIOUS_LEVELS);
    }

    /**
     * @param mixed $exception the exception as an array
     * @param int $levels previous exceptions still allowed below this one
     * @return array<string, mixed>|null the summary
     */
    private static function summary(mixed $exception, int $levels): ?array {
        if (!is_array($exception) || !is_string($exception['Exception'] ?? null) || $exception['Exception'] === '') {
            return null;
        }
        $trace = is_array($exception['Trace'] ?? null) ? array_values(array_filter($exception['Trace'], 'is_array')) : [];
        $summary = [
            'class' => self::text($exception['Exception'], 200),
            'message' => self::text(is_scalar($exception['Message'] ?? null) ? (string)$exception['Message'] : '', self::MESSAGE_LIMIT),
            'code' => is_int($exception['Code'] ?? null) ? $exception['Code'] : 0,
            'at' => self::place($exception['File'] ?? null, $exception['Line'] ?? null),
            'trace' => array_map(self::frame(...), array_slice($trace, 0, self::TRACE_FRAMES)),
            'traceFrames' => count($trace),
        ];
        $previous = $levels > 0 ? self::summary($exception['Previous'] ?? null, $levels - 1) : null;
        if ($previous !== null) {
            $summary['previous'] = $previous;
        }
        return $summary;
    }

    /**
     * @param array<string, mixed> $frame one frame of the trace
     * @return array{call:string, at:string} class->function and file:line; the arguments are left behind
     */
    private static function frame(array $frame): array {
        $function = is_string($frame['function'] ?? null) ? $frame['function'] : '';
        $class = is_string($frame['class'] ?? null) ? $frame['class'] . (is_string($frame['type'] ?? null) ? $frame['type'] : '->') : '';
        return ['call' => self::text($class . $function, 300), 'at' => self::place($frame['file'] ?? null, $frame['line'] ?? null)];
    }

    /**
     * The fields of an entry the logs tools show, redacted: the extra `data` of the core and the version stay behind.
     *
     * @param \stdClass $entry one well-formed entry of the log
     * @return array<string, mixed> time, level by name, app, user, method, url, message, reqId, userAgent, masked
     *     remoteAddr and, when the entry has one, the exception summary
     */
    public static function entry(\stdClass $entry): array {
        $field = static fn (string $name, int $limit, bool $userAgent = false): string => is_scalar($entry->{$name} ?? null) ? self::text((string)$entry->{$name}, $limit, $userAgent) : '';
        $row = [
            'time' => $field('time', 64),
            'level' => LogAnalyzer::LEVELS[(int)$entry->level] ?? (string)$entry->level,
            'app' => $field('app', 100),
            'user' => $field('user', 100),
            'method' => $field('method', 16),
            'url' => $field('url', self::MESSAGE_LIMIT),
            'message' => $field('message', self::MESSAGE_LIMIT),
            'reqId' => $field('reqId', 64),
            'userAgent' => $field('userAgent', 300, true),
            'remoteAddr' => is_string($entry->remoteAddr ?? null) ? self::ip($entry->remoteAddr) : '',
        ];
        $exception = self::exception($entry->exception ?? null);
        if ($exception !== null) {
            $row['exception'] = $exception;
        }
        return $row;
    }

    /** @return string file:line, or '' without a file */
    private static function place(mixed $file, mixed $line): string {
        if (!is_string($file) || $file === '') {
            return '';
        }
        return self::text($file, 300) . (is_int($line) ? ':' . $line : '');
    }
}
