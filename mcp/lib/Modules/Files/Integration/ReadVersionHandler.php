<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Images\OcrSupport;
use OCA\Mcp\Modules\Files\Presentation\ReadingMessages;
use OCA\Mcp\Modules\Files\Presentation\VersionMessages;
use OCA\Mcp\Modules\Files\TextExtractor;
use OCA\Mcp\Modules\Files\Integration\VersionTools;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Backs `files_version_read`: the text of one version that files_versions listed.
 *
 * The versioning check, the lookup of the listed version and the stream come from {@see VersionTools::open()}. This
 * handler reads at most {@see TextExtractor::MAX_BYTES}, closes the stream and answers with the extracted text, or
 * with the OCR notice for a scan or an image without a text layer.
 */
final class ReadVersionHandler {
	/** Characters returned before the text is truncated. */
	public const MAX_CHARS = 100000;

	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param VersionTools $versions version listing and content access
	 * @param ReadableFile $readable visible file resolver
	 * @param TextExtractor $extractor Turns the version bytes into text.
	 * @param OcrSupport $ocr Notice and state of the OCR for a version without text.
	 */
	public function __construct(
		private FilesCallContext $context,
		private VersionTools $versions,
		private \OCA\Mcp\Modules\Files\Reading\ReadableFile $readable,
		private TextExtractor $extractor,
		private OcrSupport $ocr,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json($this->read($this->readable->get($root, $arguments['path']), PathGuard::normalize($arguments['path']), $arguments['version'], $userId)));
	}

	/**
	 * @param File $file File the version belongs to.
	 * @param string $path Normalized user-relative path of $file.
	 * @param string $revision Version identifier as returned by files_versions_list.
	 * @param string $viewerUid Authenticated user.
	 * @return array{path:string, version:string, size:int, mime:string, text:string, truncated:bool, text_layer?:bool, ocr_active?:bool, notice?:string}
	 * @throws ToolFailure When versioning is off, the version does not exist, cannot be read or is too large.
	 * @throws NotFoundException When files_versions has no stored file for a version other than the current one.
	 */
	public function read(File $file, string $path, string $revision, string $viewerUid): array {
		[$version, $handle] = $this->versions->open($file, $revision, $viewerUid);
		if (!is_resource($handle)) {
			throw new ToolFailure(VersionMessages::versionUnreadable());
		}
		try {
			$bytes = (string)stream_get_contents($handle, TextExtractor::MAX_BYTES + 1);
		} finally {
			fclose($handle);
		}
		if (strlen($bytes) > TextExtractor::MAX_BYTES) {
			throw new ToolFailure(ReadingMessages::readTooLarge(TextExtractor::MAX_BYTES));
		}
		$mime = $version->getMimeType();
		$name = $version->getSourceFileName() ?: $path;
		$scannable = OcrSupport::canLackText($name, $mime);
		$text = $scannable && str_starts_with(strtolower($mime), 'image/') ? '' : $this->extractor->extractBytes($bytes, $name, $mime);
		if ($scannable && trim($text) === '') {
			$notice = $this->ocr->noTextNotice();
			return ['path' => $path, 'version' => $revision, 'size' => strlen($bytes), 'mime' => $mime, 'text' => $notice,
				'truncated' => false, 'text_layer' => false, 'ocr_active' => $this->ocr->isActive(), 'notice' => $notice];
		}
		return [
			'path' => $path,
			'version' => $revision,
			'size' => strlen($bytes),
			'mime' => $mime,
			'text' => mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) . "\n\n" . ReadingMessages::textTruncated() : $text,
			'truncated' => mb_strlen($text) > self::MAX_CHARS,
		];
	}
}
