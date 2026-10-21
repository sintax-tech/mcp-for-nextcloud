<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout\Http;

use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\ITempManager;

class BodyReader {
    private const BODY_SLACK = 1;

    public function __construct(
        private ITempManager $tempManager,
    ) {}

    /** @param callable(string): Response $consume receives a staged temporary path */
    public function withStoredBody(int $limit, callable $consume): ?Response {
        $path = $this->store($limit);
        if ($path === null) {
            return null;
        }
        try {
            return $consume($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Copies the request body into a temporary file without ever holding more than the limit in memory.
     *
     * @param int $limit effective upload limit in bytes
     * @return string|null the temporary path, or null when the body is over the limit or unreadable
     */
    private function store(int $limit): ?string {
        $path = $this->tempManager->getTemporaryFile('.mcp-upload');
        $input = $this->inputStream();
        if ($path === false || $input === false) {
            return null;
        }
        $output = @fopen($path, 'wb');
        if ($output === false) {
            fclose($input);
            return null;
        }
        try {
            $copied = stream_copy_to_stream($input, $output, $limit + self::BODY_SLACK);
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($copied === false || $copied > $limit) {
            @unlink($path);
            return null;
        }
        return $path;
    }

    /**
     * IRequest does not expose the raw body of a PUT, so php://input is the only source; it is a seam
     * so a unit test can stage the bytes.
     *
     * @return resource|false the request body
     */
    protected function inputStream() {
        return @fopen('php://input', 'rb');
    }
}
