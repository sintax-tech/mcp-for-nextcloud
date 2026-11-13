<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockMessages;
use OCA\Mcp\Platform\FileAccess\LockWriteFailure;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Throwable;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use Psr\Log\LoggerInterface;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Files\Creation\FileExists;

/** Client-safe checkout refusals and JSON receipts. */
final class CheckoutRefusals {
	/**
	 * @param LoggerInterface $logger Existing diagnostics.
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param int $status HTTP status
	 * @param string $message client-safe reason, never a token, a path on disk or an internal name
	 * @return Response the refusal, logged at debug level with the status only
	 */
	public function refuse(int $status, string $message): Response {
		$this->logger->debug('MCP checkout request refused: HTTP {status}', ['app' => 'mcp', 'status' => $status]);
		return new DataDisplayResponse($message, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
	}

	/**
	 * @param int $status HTTP status
	 * @param array<string, mixed> $payload receipt or confirmation
	 * @return Response the payload as indented JSON
	 */
	public function json(int $status, array $payload): Response {
		return new DataDisplayResponse(
			json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			$status,
			['Content-Type' => 'application/json'],
		);
	}

	/**
	 * @param LockWriteFailure $e the lock that refused an upload whose link is already spent
	 * @param string $copy user-relative path of the backup taken before, '' when there is none
	 * @return Response 423 with the explanation, where the original is, and the advice to run a new checkout
	 */
	public function lockRefusal(LockWriteFailure $e, string $copy): Response {
		return $this->refuse(Http::STATUS_LOCKED, implode(' ', array_filter([
			$e->getMessage(),
			$copy === '' ? '' : \OCA\Mcp\Modules\Files\Presentation\EditingMessages::originalKept($copy),
			LockMessages::newCheckoutNeeded(),
		])));
	}

	/**
	 * The status a refusal of a new file maps to, so the agent knows whether to rename, ask or give up.
	 *
	 * @param Throwable $e what target() or write() raised
	 * @return Response 409 for a taken name, 400 for a name or a path Nextcloud refuses, 403 for a denied folder,
	 *         404 for a missing or hidden one, 423 for a lock, 507 for a full quota and 500 for a write that failed; after
	 *         the file exists, the status of the reason with the message that names the file left
	 */
	public function createRefusal(Throwable $e): Response {
		if ($e instanceof \OCA\Mcp\Modules\Files\Creation\FileCreationFailure) {
			// The file exists: the status is the one of the reason the write stopped, the message names the file left.
			$reason = $e->getPrevious();
			return $this->refuse($reason instanceof ToolFailure ? $this->createStatus($reason) : Http::STATUS_INTERNAL_SERVER_ERROR, $e->getMessage());
		}
		if ($e instanceof ArgumentValidationException) {
			return $this->refuse(Http::STATUS_BAD_REQUEST, $e->clientMessage());
		}
		if (!$e instanceof ToolFailure) {
			$this->logger->error('MCP create upload failed: {exception_class} (code {exception_code})', ['app' => 'mcp', 'exception_class' => $e::class, 'exception_code' => ExceptionDetails::code($e)]);
			return $this->refuse(Http::STATUS_INTERNAL_SERVER_ERROR, \OCA\Mcp\Modules\Files\Presentation\CreationMessages::createFailed());
		}
		return $this->refuse($this->createStatus($e), $e->getMessage());
	}

	/**
	 * @param ToolFailure $e a refusal of target() or write()
	 * @return int the HTTP status of that refusal, as listed by {@see self::createRefusal()}
	 */
	private function createStatus(ToolFailure $e): int {
		if ($e instanceof FileTypeDenied || $e instanceof FileWritePolicyUnavailable) {
			return Http::STATUS_FORBIDDEN;
		}
		if ($e instanceof FileExists) {
			return Http::STATUS_CONFLICT;
		}
		if ($e instanceof LockWriteFailure) {
			return Http::STATUS_LOCKED;
		}
		return match ($e->getMessage()) {
			CommonMessages::forbidden() => Http::STATUS_FORBIDDEN,
			\OCA\Mcp\Modules\Files\Presentation\EditingMessages::backupPath() => Http::STATUS_BAD_REQUEST,
			CommonMessages::locked() => Http::STATUS_LOCKED,
			CommonMessages::insufficientQuota() => Http::STATUS_INSUFFICIENT_STORAGE,
			\OCA\Mcp\Modules\Files\Presentation\CreationMessages::createFailed() => Http::STATUS_INTERNAL_SERVER_ERROR,
			default => Http::STATUS_NOT_FOUND,
		};
	}
}
