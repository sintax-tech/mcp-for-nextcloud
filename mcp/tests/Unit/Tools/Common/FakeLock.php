<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Common;

use OCP\Files\Lock\ILock;

/**
 * An ILock as files_lock hands it out: the interface is the same in Nextcloud 32 to 35, and the provider fills
 * every getter. `timeout` is a duration from `createdAt` in seconds, zero or less for a lock without end.
 */
final class FakeLock implements ILock {
    public function __construct(
        private int $fileId,
        private int $type,
        private string $owner,
        private int $createdAt = 1759921500,
        private int $timeout = -1,
        private string $token = 'files_lock/00000000-0000-0000-0000-000000000001',
    ) {}

    public function getType(): int {
        return $this->type;
    }

    public function getOwner(): string {
        return $this->owner;
    }

    public function getFileId(): int {
        return $this->fileId;
    }

    public function getTimeout(): int {
        return $this->timeout;
    }

    public function getCreatedAt(): int {
        return $this->createdAt;
    }

    public function getToken(): string {
        return $this->token;
    }

    public function getDepth(): int {
        return ILock::LOCK_DEPTH_ZERO;
    }

    public function getScope(): int {
        return ILock::LOCK_EXCLUSIVE;
    }

    public function __toString(): string {
        return $this->token;
    }
}
