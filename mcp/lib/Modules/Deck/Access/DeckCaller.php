<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Access;

use OCA\Deck\Service\BoardService;
use OCP\ISession;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;

class DeckCaller {
    /** @param \Closure(): BoardService $boards */
    public function __construct(private \Closure $boards, private ISession $session) {
    }

    public function bind(string $userId): BoardService {
        if ($this->session->get('user_id') !== $userId) {
            throw new DeckSessionException('The session user is not the authenticated caller');
        }
        $boards = ($this->boards)();
        $boards->setUserId($userId);
        return $boards;
    }
}
