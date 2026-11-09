<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Backups\AfterCopyFailure;
use OCA\Mcp\Modules\Files\Presentation\CheckoutMessages;
use OCA\Mcp\Modules\Files\Presentation\EditingMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockMessages;
use OCA\Mcp\Platform\FileAccess\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * The HTTP refusal of a checkout upload that failed after its link was spent.
 *
 * Once the pre-write copy exists, a refusal says where the original is kept, as files_edit does, unless the file is no
 * longer visible: the copy mirrors a hidden file, so the answer is then 404 with the plain refusal, the one a missing
 * file gets. When the scope cannot be read to decide, the answer is 503, which asks for a new checkout: the link is spent.
 */
final class CheckoutUploadFailure {
	/**
	 * @param CheckoutRefusals $refusals Safe HTTP responses.
	 * @param LoggerInterface $logger Diagnostics of a write that failed.
	 * @param FilesAccessGuard|null $visibilityGuard Scope and exclusion tags.
	 */
	public function __construct(
		private CheckoutRefusals $refusals,
		private LoggerInterface $logger,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * @param \Throwable $error What stopped the upload.
	 * @param File $file The file being replaced.
	 * @param string $copy User-relative path of the copy, empty when copies are suspended or none was taken yet.
	 * @param bool $copied Whether the copy step finished, so a refusal must account for it.
	 * @return Response The refusal with its status.
	 */
	public function response(\Throwable $error, File $file, string $copy, bool $copied): Response {
		if ($error instanceof PolicyUnavailable) {
			return $this->refusals->refuse(Http::STATUS_SERVICE_UNAVAILABLE, CheckoutMessages::uploadUnavailableAfterSpend());
		}
		if ($copied) {
			try {
				if (!AfterCopyFailure::stillVisible($file, $this->visibilityGuard)) {
					return $this->refusals->refuse(Http::STATUS_NOT_FOUND, CommonMessages::notFound());
				}
			} catch (PolicyUnavailable) {
				return $this->refusals->refuse(Http::STATUS_SERVICE_UNAVAILABLE, CheckoutMessages::uploadUnavailableAfterSpend());
			}
		}
		$note = $copied ? ' ' . EditingMessages::originalKept($copy) : '';
		if ($error instanceof NotFoundException) {
			// Also raised after a write that went through, by metadata read for the receipt: not a write failure.
			$error = new ToolFailure(CommonMessages::notFound());
		}
		if ($error instanceof FileTypeDenied || $error instanceof FileWritePolicyUnavailable) {
			return $this->refusals->refuse(Http::STATUS_FORBIDDEN, $error->getMessage() . $note);
		}
		if ($error instanceof LockedException) {
			$error = NodeAccess::lockFailure($error);
			if (!$error instanceof LockWriteFailure) {
				return $this->refusals->refuse(Http::STATUS_LOCKED, $error->getMessage() . $note . ' ' . LockMessages::newCheckoutNeeded());
			}
		}
		if ($error instanceof LockWriteFailure) {
			// The link is spent all the same; a backup taken before the refusal holds the untouched original.
			return $this->refusals->lockRefusal($error, $copy);
		}
		if ($error instanceof ToolFailure) {
			return match ($error->getMessage()) {
				CommonMessages::conflict() => $this->refusals->refuse(Http::STATUS_CONFLICT, CheckoutMessages::uploadConflict()),
				CommonMessages::notFound() => $this->refusals->refuse(Http::STATUS_NOT_FOUND, $error->getMessage() . $note),
				default => $this->refusals->refuse(Http::STATUS_BAD_REQUEST, $error->getMessage() . $note),
			};
		}
		$this->logger->error('MCP checkout upload failed: {exception}', ['app' => 'mcp', 'exception' => ExceptionDetails::describe($error)]);
		// The copy exists by now, so the message can point at the one thing that still holds the original.
		return $this->refusals->refuse(Http::STATUS_INTERNAL_SERVER_ERROR, CheckoutMessages::uploadFailed($copy));
	}
}
