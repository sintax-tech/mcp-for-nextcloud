<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Failures;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Talk\Presentation\Messages;
use OCA\Mcp\Modules\Talk\Presentation\ToolResult;
use Psr\Log\LoggerInterface;
use Throwable;

/** Preserves Talk call and preview failures, sanitized logs and public refusal messages. */
final class TalkErrors {
	/**
	 * @param LoggerInterface $logger existing module logger
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $name public tool name used only in its existing log
	 * @param callable(): array $work unchanged call body including its result envelope
	 * @return array unchanged MCP response
	 * @throws InvalidArgumentException for a client mistake
	 * @throws PolicyUnavailable when the policy snapshot cannot be read
	 */
	public function call(string $name, callable $work): array {
		try {
			return $work();
		} catch (InvalidArgumentException $e) {
			throw $e;
		} catch (PolicyUnavailable $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->error('Talk tool failed: {tool}: {exception_detail}', [
				'app' => 'mcp',
				'tool' => $name,
				'exception' => $e::class,
				'exception_detail' => self::loggable($e),
			]);

			return ToolResult::error($this->messageFor($e));
		}
	}

	/**
	 * @param string $name public tool name used only in its existing log
	 * @param callable(): array $work unchanged plan body without an effect
	 * @return array unchanged confirmation plan
	 * @throws InvalidArgumentException for a client mistake
	 * @throws ToolFailure for a domain or unexpected failure
	 * @throws PolicyUnavailable when the policy snapshot cannot be read
	 */
	public function preview(string $name, callable $work): array {
		try {
			return $work();
		} catch (InvalidArgumentException $e) {
			throw $e;
		} catch (PolicyUnavailable $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->error('Talk tool failed: {tool}: {exception_detail}', [
				'app' => 'mcp',
				'tool' => $name,
				'exception' => $e::class,
				'exception_detail' => self::loggable($e),
			]);

			throw new ToolFailure($this->messageFor($e));
		}
	}

	/**
	 * A refusal already carries a message written for the user, so only the unexpected cases fall back to a
	 * generic one. The exception message of a Talk failure is never shown.
	 *
	 * @param Throwable $e Failure raised while running the tool
	 * @return string The message the client receives
	 */
	private function messageFor(Throwable $e): string {
		return match (true) {
			$e instanceof TalkUnavailableException => Messages::talkUnavailable(),
			$e instanceof ConversationAccessException,
			$e instanceof FileAccessException => $e->getMessage(),
			default => Messages::unexpected(),
		};
	}

	/**
	 * The failure identity for the server log: a refusal wraps the real cause, so describe that cause.
	 * Records only its class and integer code, never its message, trace or private payload.
	 *
	 * @param Throwable $e the exception being logged
	 * @return string Exception class and integer code only.
	 */
	private static function loggable(Throwable $e): string {
		return \OCA\Mcp\Mcp\Tools\ToolRegistry::loggable($e->getPrevious() ?? $e);
	}

}
