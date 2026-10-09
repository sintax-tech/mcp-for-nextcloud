<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCA\Mcp\Tools\ToolFailure;

/**
 * A write refused because the file is locked by files_lock: open in Text or Office, or locked by a person or a
 * WebDAV client. It is a ToolFailure, so every catch that already turns those into a readable error keeps working;
 * the type lets the checkout routes answer 423 without comparing translated messages.
 */
final class LockWriteFailure extends ToolFailure {
    /**
     * @param string $message translated explanation and advice, safe for the client
     * @param array<string, mixed>|null $lock the lock as {@see LockAwareWrite} describes it, null when unknown
     */
    public function __construct(string $message, public readonly ?array $lock = null) {
        parent::__construct($message);
    }

    /**
     * @param string $sentence what else the person needs to know, e.g. where the backup taken before the write is
     * @return self the same refusal with that sentence at the end
     */
    public function withNote(string $sentence): self {
        return new self($this->getMessage() . ' ' . $sentence, $this->lock);
    }
}
