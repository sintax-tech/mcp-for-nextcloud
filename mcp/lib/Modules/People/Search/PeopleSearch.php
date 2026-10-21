<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People\Search;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/** Sharing search uses the authenticated host session and its native enumeration policy. */
class PeopleSearch {
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

    /**
     * @param array<string, mixed> $arguments direct or registry-validated arguments
     * @return list<array<string, string>> exact matches first, capped after typed deduplication
     */
    public function search(array $arguments): array {
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
        return array_slice(array_values($rows), 0, $limit);
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
