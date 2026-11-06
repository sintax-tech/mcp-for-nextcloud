<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\TextExtractor;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Images\OcrSupport;

/** Preserves the existing Files ReadFileHandler behavior. */
final class ReadFileHandler {
	/**
	 * @param FilesCallContext $context existing context collaborator
	 * @param ReadableFile $readable existing readable collaborator
	 * @param NodeAccessInfo $accessInfo existing accessInfo collaborator
	 * @param TextExtractor $extractor existing extractor collaborator
	 * @param OcrSupport $ocr existing ocr collaborator
	 * @param FileTextResult $texts existing texts collaborator
	 */
	public function __construct(
		private FilesCallContext $context,
		private ReadableFile $readable,
		private NodeAccessInfo $accessInfo,
		private TextExtractor $extractor,
		private OcrSupport $ocr,
		private FileTextResult $texts,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		return $this->context->run($userId, fn (Folder $root): array => $this->read($root, $userId, $arguments['path']));
	}

	/**
	 * The text stays in content[0] exactly as it always was; content[1] carries the metadata the client
	 * needs to place the text and to know who owns the file.
	 *
	 * @return array{content: list<array{type:string, text:string}>}
	 * @param Folder $root existing operation root
	 * @param string $userId existing operation userId
	 * @param string $path existing operation path
	 */
	private function read(Folder $root, string $userId, string $path): array {
		$path = PathGuard::normalize($path);
		$file = $this->readable->get($root, $path);
		$meta = ['path' => $path, 'etag' => (string)$file->getEtag(), 'size' => (int)$file->getSize(),
			'mime' => (string)$file->getMimetype(), 'access' => $this->accessInfo->describe($file, $userId)];
		$scannable = OcrSupport::canLackText($file->getName(), (string)$file->getMimetype());
		$isImage = $scannable && str_starts_with(strtolower((string)$file->getMimetype()), 'image/');
		$text = $isImage ? '' : $this->extractor->readText($file);
		if ($scannable && trim($text) === '') {
			$notice = $this->ocr->noTextNotice();
			return $this->texts->render($notice, $meta + ['text_layer' => false, 'ocr_active' => $this->ocr->isActive(), 'notice' => $notice]);
		}
		return $this->texts->render(
			$text,
			$meta,
		);
	}
}
