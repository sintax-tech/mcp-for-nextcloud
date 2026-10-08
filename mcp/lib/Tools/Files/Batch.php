<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

/**
 * One executed batch, as the record the undo needs: where each node came from, where it went, and the id it
 * answered with right after the move.
 *
 * The id is the whole reason this exists. Undoing "put it back" is only safe while the node at the
 * destination is still the node the batch put there, and the only way to know is to remember the id.
 */
final class Batch {
    /**
     * @param int|null $id row id, null before it is stored
     * @param string $userId the account the batch belongs to
     * @param int $createdAt when the batch ran
     * @param list<array{from:string, to:string, toId:int}> $moves completed moves, in the order they ran
     * @param list<string> $dirs folders the batch itself created
     * @param int|null $undoneAt when it was undone, null while it is still undoable
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $userId,
        public readonly int $createdAt,
        public readonly array $moves,
        public readonly array $dirs,
        public readonly ?int $undoneAt,
    ) {}

    /**
     * @param array<string, mixed> $row a row of mcp_file_batches
     */
    public static function fromRow(array $row): self {
        return new self(
            (int)$row['id'],
            (string)$row['user_id'],
            (int)$row['created_at'],
            self::decode((string)$row['moves_json']),
            self::decode((string)$row['dirs_json']),
            $row['undone_at'] === null ? null : (int)$row['undone_at'],
        );
    }

    /** @return array<string, mixed> the row without its id, ready for an insert */
    public function toRow(): array {
        return [
            'user_id' => $this->userId,
            'created_at' => $this->createdAt,
            'moves_json' => json_encode($this->moves, JSON_THROW_ON_ERROR),
            'dirs_json' => json_encode($this->dirs, JSON_THROW_ON_ERROR),
            'undone_at' => $this->undoneAt,
        ];
    }

    /** @return bool whether this batch was already undone and cannot be undone again */
    public function isUndone(): bool {
        return $this->undoneAt !== null;
    }

    /**
     * @param string $json a JSON array written by toRow()
     * @return list<mixed> the decoded list, empty when the column is unreadable
     */
    private static function decode(string $json): array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
