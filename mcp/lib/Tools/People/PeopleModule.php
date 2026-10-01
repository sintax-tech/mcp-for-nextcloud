<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\People;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * People lookup: finds Nextcloud account IDs by name, ID or e-mail, so the model never has to guess who to
 * invite or message. It goes through the sharing search (the one behind the Files share dialog), so the
 * administrator's enumeration and group restrictions apply. Never lists everybody: a term is always required.
 *
 * Groups are the same search with one more type: `include_groups` asks the core for the group matches too, and the
 * group restrictions (group sharing off, enumeration, sharing restricted to the groups of the caller, hidden
 * groups) are the ones of the GroupPlugin, because that is the plugin the core runs. An account is published as
 * it always was plus its type; a group carries the group ID a share needs.
 *
 * A failure of the core search becomes a fixed message and the log keeps the exception class only: its message may
 * carry the term, which is user text, and nothing else must reach the registry, whose log keeps what escapes.
 */
class PeopleModule implements ToolModule, ToolGuideNotes {
    /** Shortest accepted search term, in characters. */
    public const MIN_TERM = 2;
    /** Longest accepted search term, in characters. */
    public const MAX_TERM = 100;
    /** Default and maximum number of accounts returned. */
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 25;

    /** Value of the `type` field of an account row. */
    private const TYPE_USER = 'user';
    /** Value of the `type` field of a group row. */
    private const TYPE_GROUP = 'group';
    /** Result sets of the core search, by the type of row they hold. */
    private const RESULT_SETS = [self::TYPE_USER => 'users', self::TYPE_GROUP => 'groups'];

    public function __construct(
        private ISearch $search,
        private LoggerInterface $logger,
    ) {}

    /** @return list<string> short behaviour notes about this module */
    public function guideNotes(): array {
        return [
            'Before acting on a person (inviting, mentioning, messaging), when the user did not give the account ID, '
                . 'call users_search and confirm with the user when more than one account matches. Never invent an ID.',
            'users_search follows the sharing settings of the administrator: it may return nobody for a partial term.',
            'To share something with a whole group, call users_search with include_groups: true and take the ID of the '
                . 'row whose type is "group": a share takes it as group:<id>. Groups are not searched without that '
                . 'argument, and the same administrator restrictions of accounts apply to them.',
        ];
    }

    /** @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string}> */
    public function definitions(): array {
        return [[
            'name' => 'users_search',
            'description' => 'Searches the accounts of this Nextcloud by name, account ID or e-mail and returns their account IDs '
                . '(uid), display names and, when available, e-mail; every row carries a type, "user" for an account. '
                . 'Use it whenever you are not sure of someone\'s account ID. Groups are not searched by default: to also match '
                . 'groups, pass include_groups: true, and the group rows come back with the type "group" and the group ID, '
                . 'which is what a share takes as group:<id>.',
            'module' => 'people',
            'operation' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'minLength' => self::MIN_TERM, 'maxLength' => self::MAX_TERM,
                        'description' => 'Name, account ID or e-mail to look for (2 to 100 characters).'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT,
                        'description' => 'Maximum number of accounts returned (default 10, at most 25).'],
                    'include_groups' => ['type' => 'boolean', 'default' => false,
                        'description' => 'Also match groups, for sharing with a group: they come back with the type "group" and the group ID (default false).'],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
        ]];
    }

    /**
     * @param string $name tool name from definitions()
     * @param array<string, mixed> $arguments arguments, defaults applied
     * @param string $userId authenticated user (unused: the sharing search reads the current session)
     * @return array{content: list<array{type:string, text:string}>, isError?: bool}
     * @throws InvalidArgumentException for an unknown tool, a term out of bounds or a limit out of range
     * @throws ToolFailure when the core search fails, with a fixed message that never carries the term
     */
    public function call(string $name, array $arguments, string $userId): array {
        if ($name !== 'users_search') {
            throw new InvalidArgumentException('Unknown tool');
        }
        $term = trim((string)($arguments['query'] ?? ''));
        $length = mb_strlen($term);
        $limit = (int)($arguments['limit'] ?? self::DEFAULT_LIMIT);
        if ($length < self::MIN_TERM || $length > self::MAX_TERM || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('Invalid search term or limit');
        }
        $includeGroups = (bool)($arguments['include_groups'] ?? false);
        $types = $includeGroups ? [IShare::TYPE_USER, IShare::TYPE_GROUP] : [IShare::TYPE_USER];
        try {
            [$result] = $this->search->search($term, $types, false, $limit, 0);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP people search failed', ['app' => 'mcp', 'exception_class' => $e::class]);
            throw new ToolFailure(Translator::t('The search for people failed; try again.'));
        }
        $rows = [];
        foreach ($this->matches($result, $includeGroups) as [$type, $match]) {
            $id = (string)($match['value']['shareWith'] ?? '');
            // An account and a group may share a name, so the type is part of the key and only the core's
            // repetition of the same match is collapsed.
            if ($id === '' || isset($rows[$type . ':' . $id])) {
                continue;
            }
            $rows[$type . ':' . $id] = $type === self::TYPE_GROUP
                ? ['type' => self::TYPE_GROUP, 'id' => $id, 'displayName' => (string)($match['label'] ?? $id)]
                : $this->account($id, $match);
        }
        return ToolResult::json(array_slice(array_values($rows), 0, $limit));
    }

    /**
     * The matches of a core result, in the order they are worth reading: the exact hits before the partial ones,
     * accounts before groups inside each of the two. Group sets are read only when the caller asked for groups.
     *
     * @param array<string, mixed> $result ISearch result of the core
     * @param bool $includeGroups whether the caller asked for groups
     * @return list<array{0:string, 1:array<string, mixed>}> the type of the row and the match of the core
     */
    private function matches(array $result, bool $includeGroups): array {
        $matches = [];
        // The core nests the exact hits under 'exact' and the partial ones at the top level of the same keys.
        foreach ([$result['exact'] ?? [], $result] as $tier) {
            foreach (self::RESULT_SETS as $type => $set) {
                if ($type === self::TYPE_GROUP && !$includeGroups) {
                    continue;
                }
                foreach ((array)($tier[$set] ?? []) as $match) {
                    if (is_array($match)) {
                        $matches[] = [$type, $match];
                    }
                }
            }
        }
        return $matches;
    }

    /**
     * An account row: the fields 0.9 already published, plus the type that tells them from a group.
     *
     * @param string $uid account ID of the match
     * @param array<string, mixed> $match match of the core
     * @return array<string, string> row of the account
     */
    private function account(string $uid, array $match): array {
        $row = ['type' => self::TYPE_USER, 'uid' => $uid, 'displayName' => (string)($match['label'] ?? $uid)];
        $email = (string)($match['subline'] ?? '');
        if ($email !== '' && str_contains($email, '@') && !str_contains($email, ' ')) {
            $row['email'] = $email;
        }
        return $row;
    }
}
