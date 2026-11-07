<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Deletion;

use Closure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Deck\Attachments\CardFiles;
use OCA\Mcp\Modules\Deck\Integration\CardFormatter;
use OCA\Mcp\Mcp\Confirmation\PlanState;

/** Prepares only the current visible file unlink plan. */
final class UnlinkCardFilePreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context per-call composition and failure boundary */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged plan with its caller timezone
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(UnlinkCardFileHandler::TOOL, fn (): array => self::prepare($arguments, $userId, $this->context->files(), $this->context->formatter($userId), fn () => $this->context->planState()), $userId);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId caller UID
	 * @param CardFiles $files file access boundary
	 * @param CardFormatter $formatter caller formatter
	 * @param Closure():PlanState $stateFactory lazy HMAC
	 * @return array<string,mixed> unchanged file plan
	 */
	public static function prepare(array $arguments, string $userId, CardFiles $files, CardFormatter $formatter, Closure $stateFactory): array {
		if (array_key_exists('file_state', $arguments)) {
			throw new ArgumentValidationException('Invalid arguments', 'file_state', 'only accepted with confirm:true');
		}
		return self::fromState($files->state($userId, (int)$arguments['cardId'], (int)$arguments['attachmentId']), $userId, $formatter, $stateFactory);
	}

	/**
	 * @param array<string,mixed> $state fresh file state
	 * @param string $userId caller UID
	 * @param CardFormatter $formatter caller formatter
	 * @param Closure():PlanState $stateFactory lazy HMAC
	 * @return array<string,mixed> unchanged signed file plan
	 */
	public static function fromState(array $state, string $userId, CardFormatter $formatter, Closure $stateFactory): array {
		$context = $state['context'];
		$board = $context['board'] + ['shared' => $context['board']['owner'] !== $userId];
		return ['action' => UnlinkCardFileHandler::TOOL, 'card' => $formatter->reference($context['card'], $context['boardId']),
			'board' => $board, 'file' => $state['file'], 'attachmentId' => $state['snapshot']['attachmentId'],
			'createdBy' => $state['file_state']['createdBy'], 'file_state' => $state['file_state'],
			'shared' => $board['shared'] ? [$board] : [], 'recoverable' => false,
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::fileUnlinkWarning(),
			PlanState::ARGUMENT => ($stateFactory)()->of(UnlinkCardFileHandler::TOOL, $userId, $state['snapshot'])];
	}
}
