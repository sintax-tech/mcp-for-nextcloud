<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants;

use InvalidArgumentException;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Read model of what a reset to inheritance leaves, cell by cell: the value and source of the cell now, and the value
 * and source it has once the individual value is gone (contracts §2.3, plan "Filtros, contagens e UI"). It backs the
 * preview of the admin page and nothing else: admin counting only, not an access decision. The "after" is the group-only
 * resolution of PolicyOperationContext::inherited(), which only a grant-only context answers, so this class reads
 * through PolicyResolver::beginManyGrants() like the group statistics do and never through a context that serves tools.
 *
 * Read only: no write, no transaction, no mutex and no observation of group trust (a group that does not satisfy the
 * source predicate simply does not grant here, as in any read outside a command). Unknown stays null, never false: an
 * indeterminate group policy that decides the result makes "after" unknown. One resolution per user of the request, in
 * batch; the directory is read per group of the users, so the cost is that of one matrix page. Like every directory read
 * it refuses to run inside a transaction, and its answer is paired with the policy revision it was read at: a revision
 * that moved during the read is read again once, then the preview is unavailable (never a hybrid answer).
 */
class OverridePreview {
    public function __construct(
        private PolicyResolver $resolver,
        private IUserManager $users,
        private IDBConnection $db,
        private PolicyStateMapper $state,
    ) {}

    /**
     * @param mixed $commands the body of a reset: a list of {uid, module, operation, state: "inherit"}, the same shape
     *     as POST /api/grants/commands, so the page sends one body to both
     * @return array{revision:string, cells: list<array{uid:string, module:?string, operation:string, before:array{allowed:?bool, source:string, groupIds:list<string>}, after:array{allowed:?bool, source:string, groupIds:list<string>}}>}
     * @throws InvalidArgumentException for a shape, state, user or cell the command would refuse, or too many users or cells
     * @throws PolicyUnavailable when the policy cannot be read
     */
    public function preview(mixed $commands): array {
        if (!is_array($commands) || $commands === [] || !array_is_list($commands) || count($commands) > PolicyLimits::MAX_CELLS) {
            throw new InvalidArgumentException('Invalid request');
        }
        $cells = [];
        foreach ($commands as $command) {
            if (!is_array($command) || array_diff(array_keys($command), ['uid', 'module', 'operation', 'state']) !== []
                || !array_key_exists('module', $command) || !is_string($command['uid'] ?? null) || !is_string($command['operation'] ?? null)
                || !(is_string($command['module']) || $command['module'] === null) || ($command['state'] ?? null) !== 'inherit'
                || PolicyEventPayload::userKey($command['module'], $command['operation']) === null) {
                throw new InvalidArgumentException('Invalid request');
            }
            $cells[$command['uid'] . "\0" . ($command['module'] ?? '') . "\0" . $command['operation']] = $command;
        }
        $uids = array_values(array_unique(array_column($cells, 'uid')));
        if (count($uids) > PolicyLimits::MAX_UIDS) {
            throw new InvalidArgumentException('Invalid request');
        }
        foreach ($uids as $uid) {
            if ($uid === '' || $this->users->get($uid) === null) {
                throw new InvalidArgumentException('Invalid request');
            }
        }
        if ($this->db->inTransaction()) {
            throw new PolicyUnavailable('Directory reads cannot run inside a transaction');
        }
        try {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $revision = $this->state->state()['revision'];
                $out = $this->read($cells, $uids);
                if ($revision === $this->state->state()['revision']) {
                    return ['revision' => $revision, 'cells' => $out];
                }
            }
            throw new PolicyUnavailable('Policy changed while it was read');
        } catch (PolicyUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PolicyUnavailable('Cannot read the policy', previous: $e);
        }
    }

    /**
     * @param array<string, array{uid:string, module:?string, operation:string}> $cells distinct cells
     * @param list<string> $uids their users
     * @return list<array{uid:string, module:?string, operation:string, before:array, after:array}>
     */
    private function read(array $cells, array $uids): array {
        $contexts = $this->resolver->beginManyGrants($uids);
        try {
            $out = [];
            foreach ($cells as $cell) {
                $context = $contexts[$cell['uid']] ?? throw new PolicyUnavailable('Policy unavailable');
                $eligibility = $cell['module'] === null;
                $before = $eligibility ? $context->eligible() : $context->granted($cell['module'], $cell['operation']);
                $after = $context->inherited($eligibility ? '_system' : $cell['module'], $eligibility ? 'eligible' : $cell['operation']);
                $out[] = ['uid' => $cell['uid'], 'module' => $cell['module'], 'operation' => $cell['operation'],
                    'before' => ['allowed' => $before->allowed, 'source' => $before->source, 'groupIds' => $before->groupIds],
                    'after' => ['allowed' => $after->allowed, 'source' => $after->source, 'groupIds' => $after->groupIds]];
            }
            return $out;
        } finally {
            foreach ($contexts as $context) {
                $context->close();
            }
        }
    }
}
