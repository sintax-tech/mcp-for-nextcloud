<?php
/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Logs\Reading;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IConfig;
use OCP\Log\IFileBased;
use OCP\Log\ILogFactory;

/** Resolves only the current file published by the official logging API; no user/config-selected path. */
class LogReader {
	/** @param ILogFactory $factory Official logger factory. @param IConfig $config Server's eligible log level. */
	public function __construct(private ILogFactory $factory, private IConfig $config) {}

	/** @return ReverseLogFile One read-only handle. @throws ToolFailure On a non-file writer or unreadable file. */
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

	/** @return int Same eligibility threshold as the core file writer. */
	public function minimumLevel(): int {
		return $this->config->getSystemValueInt('loglevel', 2);
	}
}
