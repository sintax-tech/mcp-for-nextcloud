<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Modules\Files\Extraction\FileReadLimit;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Admin JSON API of the files_read byte ceiling, so it never needs occ. Admin-only and CSRF-protected by
 * Nextcloud's defaults. The limit is edited in whole MiB and stored in bytes under FileReadLimit::MAX_BYTES_KEY.
 * The effective limit never exceeds the share of memory_limit a PDF read is budgeted to use.
 */
class FileReadLimitController extends Controller {
	/** Bytes per MiB. */
	private const MIB = 1024 * 1024;
	/** Highest limit accepted, in MiB (100 GiB); it only bounds the input. Memory still caps what is effective. */
	public const MAX_MIB = 102400;

	public function __construct(
		string $appName,
		IRequest $request,
		private FileReadLimit $limit,
		private IConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse the limit (see state())
	 */
	public function show(): JSONResponse {
		return new JSONResponse($this->state());
	}

	/**
	 * Body {mib: int}, a whole number of MiB from 1 to MAX_MIB.
	 *
	 * @return JSONResponse the new limit (see state()), or 400 without writing anything
	 */
	public function update(): JSONResponse {
		$mib = $this->request->getParam('mib');
		if (!is_int($mib) || $mib < 1 || $mib > self::MAX_MIB) {
			return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
		}
		$this->config->setAppValue($this->appName, FileReadLimit::MAX_BYTES_KEY, (string)($mib * self::MIB));
		return new JSONResponse($this->state());
	}

	/**
	 * @return array{configuredBytes:int, effectiveBytes:int, phpBytes:int, ceilingBytes:int|null, phpUnlimited:bool, defaultBytes:int} sizes in bytes; ceilingBytes is null when PHP sets no memory_limit
	 */
	private function state(): array {
		$unlimited = $this->limit->phpUnlimited();
		$php = $this->limit->phpMaxBytes();
		return [
			'configuredBytes' => $this->limit->configuredMaxBytes(),
			'effectiveBytes' => $this->limit->maxBytes(),
			'phpBytes' => $php,
			'ceilingBytes' => $unlimited ? null : FileReadLimit::ceilingBytes($php),
			'phpUnlimited' => $unlimited,
			'defaultBytes' => FileReadLimit::DEFAULT_MAX_BYTES,
		];
	}
}
