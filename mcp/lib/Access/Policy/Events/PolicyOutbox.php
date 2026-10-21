<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Events;

use InvalidArgumentException;
use OCA\Mcp\Db\PolicyEventsMapper;

/**
 * Events a policy command emits during one attempt. The writer persists them in the attempt's own transaction, after
 * the command and the revision CAS and before the commit, so they are rolled back with it. Ids derive from the
 * logical command id, which survives retries: a replayed attempt emits the same ids instead of duplicates.
 */
final class PolicyOutbox {
    /** @var list<array{id:string, kind:string, payload:array}> */
    private array $events = [];

    public function __construct(private string $commandId) {}

    /**
     * @param string $kind one of PolicyEventsMapper::KINDS
     * @param array<array-key, mixed> $payload JSON-encodable data, validated by the producer for its kind
     * @return string the event id, stable across retries of the same command
     * @throws InvalidArgumentException for an unknown kind
     */
    public function add(string $kind, array $payload): string {
        if (!in_array($kind, PolicyEventsMapper::KINDS, true)) {
            throw new InvalidArgumentException('Unknown policy event kind');
        }
        $id = $this->commandId . '-' . (count($this->events) + 1);
        $this->events[] = ['id' => $id, 'kind' => $kind, 'payload' => $payload];
        return $id;
    }

    /** @return list<array{id:string, kind:string, payload:array}> in emission order */
    public function events(): array {
        return $this->events;
    }
}
