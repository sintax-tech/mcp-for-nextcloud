<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Reading;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IConfig;
use OCP\Log\IFileBased;
use OCP\Log\ILogFactory;

/**
 * The one way the logs module resolves the current server log: the core's file writer, through ILogFactory and
 * IFileBased::getLogFilePath(). No user/config-selected path, shell, occ or rotated file is read.
 */
class LogReader {
	/**
	 * Reads through the core's file writer.
	 *
	 * @param ILogFactory $factory core factory the file writer is taken from
	 * @param IConfig $config server's eligible log level
	 */
	public function __construct(private ILogFactory $factory, private IConfig $config) {}

	/**
	 * @return ReverseLogFile one read-only handle; a nonexistent file is an empty log
	 * @throws ToolFailure when the log is not a file or cannot be read, with a message that names no path
	 */
	public function open(): ReverseLogFile {
		try {
			$writer = $this->factory->get('file');
		} catch (\Throwable) {
			throw new ToolFailure(Translator::t('The server log could not be read.'));
		}
		if (!$writer instanceof IFileBased) {
			throw new ToolFailure(Translator::t('This server does not write its log to a file, so there is nothing to read.'));
		}
		try {
			return new ReverseLogFile($writer->getLogFilePath());
		} catch (\Throwable) {
			throw new ToolFailure(Translator::t('The server log could not be read.'));
		}
	}

	/**
	 * @return int same eligibility threshold as the core file writer
	 */
	public function minimumLevel(): int {
		return $this->config->getSystemValueInt('loglevel', 2);
	}
}
