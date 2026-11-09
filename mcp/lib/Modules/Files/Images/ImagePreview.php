<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use OCP\Files\File;
use OCP\IConfig;
use OCP\IPreview;
use Psr\Log\LoggerInterface;

/** Renders preview bytes within the unchanged per-image and batch budgets. */
final class ImagePreview {
	/** MIME types that are safe to return as-is when no preview provider accepts the file. */
	private const RETURNABLE_ORIGINAL_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
	/**
	 * @param IPreview $preview Native image preview provider.
	 * @param IConfig $config App configuration for image byte budgets.
	 * @param LoggerInterface $logger Safe diagnostics for failed image operations.
	 */
	public function __construct(
		private IPreview $preview,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Renders a preview, shrinking max_size until the bytes fit the per-image limit.
	 *
	 * @param File $file source image
	 * @param int $maxSize initial max dimension in pixels
	 * @param int $byteLimit per-image byte limit
	 * @return array{0:string, 1:string, 2:int} bytes, mimeType, effective max_size used
	 * @throws ToolFailure when no preview can be produced and the original cannot be returned
	 */
	public function renderPreview(File $file, int $maxSize, int $byteLimit): array {
		$current = $maxSize;
		for ($attempt = 0; $attempt < 4; $attempt++) {
			try {
				$simple = $this->preview->getPreview($file, $current, $current, false);
			} catch (\Throwable $e) {
				$this->logger->debug('MCP image preview failed: {exception_class} (code {exception_code})', ['app' => 'mcp', 'exception_class' => $e::class, 'exception_code' => ExceptionDetails::code($e)]);
				break;
			}
			$bytes = $simple->getContent();
			if (strlen($bytes) <= $byteLimit) {
				return [$bytes, $simple->getMimeType(), $current];
			}
			$current = max(ImageLimits::MIN_MAX_SIZE, (int)floor($current / 2));
			if ($current === ImageLimits::MIN_MAX_SIZE && strlen($bytes) <= $byteLimit) {
				return [$bytes, $simple->getMimeType(), $current];
			}
		}
		// Preview missing or still too big: fall back to the original when it is a safe raster format
		// under the byte limit; otherwise the file cannot be shown.
		$mime = strtolower((string)$file->getMimetype());
		if (in_array($mime, self::RETURNABLE_ORIGINAL_MIMES, true)) {
			$size = (int)$file->getSize();
			if ($size > 0 && $size <= $byteLimit) {
				return [(string)$file->getContent(), $mime, $maxSize];
			}
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ImageMessages::imagePreviewTooLarge());
		}
		throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ImageMessages::imageUnsupported());
	}

	/** @return int per-image byte limit, from app config with a sane default and a lower floor */
	public function singleMaxBytes(): int {
		$configured = (int)$this->config->getAppValue('mcp', ImageLimits::SINGLE_MAX_BYTES_KEY, (string)ImageLimits::DEFAULT_SINGLE_MAX_BYTES);
		return $configured > 0 ? $configured : ImageLimits::DEFAULT_SINGLE_MAX_BYTES;
	}

	/** @return int batch budget, from app config with a sane default */
	public function batchMaxBytes(): int {
		$configured = (int)$this->config->getAppValue('mcp', ImageLimits::BATCH_MAX_BYTES_KEY, (string)ImageLimits::DEFAULT_BATCH_MAX_BYTES);
		return $configured > 0 ? $configured : ImageLimits::DEFAULT_BATCH_MAX_BYTES;
	}
}
