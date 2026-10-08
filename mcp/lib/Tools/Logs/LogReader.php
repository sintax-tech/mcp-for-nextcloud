<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Log\IFileBased;
use OCP\Log\ILogFactory;

/**
 * The one way the logs module reads the server log: the core's file writer, through ILogFactory and
 * IFileBased::getEntries(), the same path the core's log reader takes. No file is opened here, no shell and no occ.
 *
 * getEntries() reads the current file from the end, keeps the entries at or above the `loglevel` setting and decodes
 * each line with json_decode(), so a malformed line comes back as null. The rotated file (nextcloud.log.1) is not read.
 */
class LogReader {
    public function __construct(private ILogFactory $factory) {}

    /**
     * @param int $limit entries to read at most
     * @param int $offset entries to skip from the end first
     * @return list<mixed> decoded entries, newest first; anything that is not an object is a malformed line
     * @throws ToolFailure when the log is not a file or the core cannot read it, with a message that names no path
     */
    public function entries(int $limit, int $offset): array {
        try {
            $writer = $this->factory->get('file');
        } catch (\Throwable) {
            throw new ToolFailure(Translator::t('The server log could not be read.'));
        }
        if (!$writer instanceof IFileBased) {
            throw new ToolFailure(Translator::t('This server does not write its log to a file, so there is nothing to read.'));
        }
        try {
            return array_values($writer->getEntries($limit, $offset));
        } catch (\Throwable) {
            throw new ToolFailure(Translator::t('The server log could not be read.'));
        }
    }
}
