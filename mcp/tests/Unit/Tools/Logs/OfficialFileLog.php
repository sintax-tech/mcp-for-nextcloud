<?php
/* SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use OCP\Log\IFileBased;
use OCP\Log\IWriter;

/** Offline oracle: getEntries copied from server v34.0.4/lib/private/Log/File.php, only WARN replaced by 2.
 * https://github.com/nextcloud/server/blob/v34.0.4/lib/private/Log/File.php
 */
final class OfficialFileLog implements IWriter, IFileBased {
	private object $config;
	/** @param string $logFile Disposable fixture. @param int $minimum Official configured threshold. */
	public function __construct(private string $logFile, int $minimum) {
		$this->config = new class($minimum) {
			/** @param int $minimum Fixture loglevel. */
			public function __construct(private int $minimum) {}
			/** @param string $key Config key. @param int $default Default. @return int Fixture threshold. */
			public function getValue(string $key, int $default): int { return $this->minimum; }
		};
	}
	/** @return string Official API path. */
	public function getLogFilePath(): string { return $this->logFile; }
	/** @param string $app Unused. @param mixed $message Unused. @param int $level Unused. @return void Read-only oracle. */
	public function write(string $app, $message, int $level) {}
	/** @param int $limit Native limit. @param int $offset Eligible-entry offset. @return array Core decoded entries. */
	public function getEntries(int $limit = 50, int $offset = 0): array {
		$minLevel = $this->config->getValue('loglevel', 2);
		$entries = [];
		$handle = @fopen($this->logFile, 'rb');
		if ($handle) {
			fseek($handle, 0, SEEK_END);
			$pos = ftell($handle);
			$line = '';
			$entriesCount = 0;
			$lines = 0;
			// Loop through each character of the file looking for new lines
			while ($pos >= 0 && ($limit === null || $entriesCount < $limit)) {
				fseek($handle, $pos);
				$ch = fgetc($handle);
				if ($ch == "\n" || $pos == 0) {
					if ($line !== '') {
						// Add the first character if at the start of the file,
						// because it doesn't hit the else in the loop
						if ($pos == 0) {
							$line = $ch . $line;
						}
						$entry = json_decode($line);
						// Add the line as an entry if it is passed the offset and is equal or above the log level
						if ($entry->level >= $minLevel) {
							$lines++;
							if ($lines > $offset) {
								$entries[] = $entry;
								$entriesCount++;
							}
						}
						$line = '';
					}
				} else {
					$line = $ch . $line;
				}
				$pos--;
			}
			fclose($handle);
		}
		return $entries;
	}
}
