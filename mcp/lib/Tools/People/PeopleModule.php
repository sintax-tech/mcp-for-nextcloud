<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\People;

use InvalidArgumentException;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;

/**
 * People lookup: finds Nextcloud account IDs by name, ID or e-mail, so the model never has to guess who to
 * invite or message. It goes through the sharing search (the one behind the Files share dialog), so the
 * administrator's enumeration and group restrictions apply. Never lists everybody: a term is always required.
 */
class PeopleModule implements ToolModule, ToolGuideNotes {
    /** Shortest accepted search term, in characters. */
    public const MIN_TERM = 2;
    /** Longest accepted search term, in characters. */
    public const MAX_TERM = 100;
    /** Default and maximum number of accounts returned. */
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 25;

    public function __construct(private ISearch $search) {}

    /** @return list<string> short behaviour notes about this module */
    public function guideNotes(): array {
        return [
            'Before acting on a person (inviting, mentioning, messaging), when the user did not give the account ID, '
                . 'call users_search and confirm with the user when more than one account matches. Never invent an ID.',
            'users_search follows the sharing settings of the administrator: it may return nobody for a partial term.',
        ];
    }

    /** @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string}> */
    public function definitions(): array {
        return [[
            'name' => 'users_search',
            'description' => 'Searches the accounts of this Nextcloud by name, account ID or e-mail and returns their account IDs '
                . '(uid), display names and, when available, e-mail. Use it whenever you are not sure of someone\'s account ID.',
            'module' => 'people',
            'operation' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'minLength' => self::MIN_TERM, 'maxLength' => self::MAX_TERM,
                        'description' => 'Name, account ID or e-mail to look for (2 to 100 characters).'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT,
                        'description' => 'Maximum number of accounts returned (default 10, at most 25).'],
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
        [$result] = $this->search->search($term, [IShare::TYPE_USER], false, $limit, 0);
        $rows = [];
        foreach (array_merge($result['exact']['users'] ?? [], $result['users'] ?? []) as $match) {
            $uid = (string)($match['value']['shareWith'] ?? '');
            if ($uid === '' || isset($rows[$uid])) {
                continue;
            }
            $row = ['uid' => $uid, 'displayName' => (string)($match['label'] ?? $uid)];
            $email = (string)($match['subline'] ?? '');
            if ($email !== '' && str_contains($email, '@') && !str_contains($email, ' ')) {
                $row['email'] = $email;
            }
            $rows[$uid] = $row;
        }
        return ToolResult::json(array_slice(array_values($rows), 0, $limit));
    }
}
