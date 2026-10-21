<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Contracts;


interface CardReferenceReader {
    /** @return array{cardId:int,boardId:int,title:string} */
    public function read(string $userId, int $cardId): array;
}
