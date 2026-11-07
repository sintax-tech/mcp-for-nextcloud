<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Reading;

use RuntimeException;

/** One read-only handle to the current OCP-published log, with bounded reverse blocks and a capped line buffer. */
final class ReverseLogFile {
	/** Bytes per read, independent of the file size. */
	public const BLOCK_BYTES = 65536;
	/** Largest complete line decoded; larger lines are explicit unparsed slots. */
	public const MAX_LINE_BYTES = 16777216;
	/** @var resource File handle, never a rotated or caller-selected path. */
	private $handle;
	private int $position;
	private string $buffer = '';
	/** @var list<string> Reverse-ordered fragments of at most one bounded line. */
	private array $fragments = [];
	private int $lineBytes = 0;
	private bool $oversized = false;
	private bool $timedOut = false;

	/** @param string $path Only IFileBased::getLogFilePath may supply this path. */
	public function __construct(string $path) {
		$this->handle = @fopen($path, 'rb');
		if ($this->handle === false) {
			if (!file_exists($path)) {
				$this->position = 0;
				return;
			}
			throw new RuntimeException('Cannot open the current log');
		}
		$stat = fstat($this->handle);
		if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
			$this->close();
			throw new RuntimeException('The current log is not a regular file');
		}
		$this->position = $stat['size'];
	}

	/**
	 * @param LogDeadline $deadline Shared call deadline.
	 * @return array{entry:mixed,oversized:bool,bytes:int}|null Next nonempty physical line, or EOF/time pause.
	 */
	public function next(LogDeadline $deadline): ?array {
		while ($this->position > 0 || $this->buffer !== '' || $this->lineBytes > 0) {
			if ($deadline->expired()) {
				$this->timedOut = true;
				return null;
			}
			$newline = strrpos($this->buffer, "\n");
			$fragment = $newline === false ? $this->buffer : substr($this->buffer, $newline + 1);
			$this->buffer = $newline === false ? '' : substr($this->buffer, 0, $newline);
			$this->lineBytes += strlen($fragment);
			if ($newline === false && $this->position > 0
				&& ($this->lineBytes > self::MAX_LINE_BYTES || $this->oversized)) {
				$this->oversized = true;
				$this->fragments = [];
			} elseif (!$this->oversized) {
				$this->fragments[] = $fragment;
			}
			if ($newline !== false || $this->position === 0) {
				$bytes = $this->lineBytes;
				$oversized = $this->oversized || $bytes > self::MAX_LINE_BYTES;
				$line = $oversized ? '' : implode('', array_reverse($this->fragments));
				$this->lineBytes = 0;
				$this->oversized = false;
				$this->fragments = [];
				if ($bytes === 0) {
					continue;
				}
				return ['entry' => $oversized ? null : json_decode($line), 'oversized' => $oversized, 'bytes' => $bytes];
			}
			$length = min(self::BLOCK_BYTES, $this->position);
			$this->position -= $length;
			if (fseek($this->handle, $this->position) !== 0) {
				throw new RuntimeException('Cannot seek the current log');
			}
			$chunk = fread($this->handle, $length);
			if ($chunk === false || strlen($chunk) !== $length) {
				throw new RuntimeException('The current log changed or could not be read');
			}
			$this->buffer = $chunk;
		}
		return null;
	}

	/** @return bool Whether the shared deadline interrupted this reader before EOF. */
	public function timedOut(): bool {
		return $this->timedOut;
	}

	/** @return void Release this request's handle. */
	public function close(): void {
		if (is_resource($this->handle)) {
			fclose($this->handle);
		}
	}

	/** Releases the handle if a caller failed before its normal finally. */
	public function __destruct() {
		$this->close();
	}
}
