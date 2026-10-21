<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Files;

use OCA\Mcp\Modules\Files\Checkout\Http\BodyReader;

final class StagedBodyReader extends BodyReader {
    public string $staged = '';

    protected function inputStream() {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $this->staged);
        rewind($stream);
        return $stream;
    }
}
