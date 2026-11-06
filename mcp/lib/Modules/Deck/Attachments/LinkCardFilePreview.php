<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Attachments;

/** Prepares the existing complete file link warning without a native creation. */
final class LinkCardFilePreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context per-call composition and failure boundary */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged plan with its caller timezone
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(LinkCardFileHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged link warning and signed state
	 */
	private function prepare(array $arguments, string $userId): array {
		$services = $this->context->linkServices();
		return $services['plans']->of($services['prepare']->prepare($userId, (int)$arguments['cardId'], (int)$arguments['fileId']), $userId);
	}
}
