<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Reading;

use Closure;
use OCA\Mcp\Modules\Deck\Attachments\CardFiles;
use OCA\Mcp\Modules\Deck\Integration\CardFormatter;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use Psr\Log\LoggerInterface;

/** Lists authorized existing file references, or unlinks exactly one without dispatching legacy deletion. */
final class ListCardFilesHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	public const TOOL = 'deck_list_card_files';
	private ?CardFiles $access = null;

	/**
	 * @param DeckGatewayInterface $gateway Native boundary for the shared-board gate.
	 * @param LoggerInterface $logger Receives exception classes only.
	 * @param Closure(): CardFiles $filesFactory Lazy Files grant/scope/exclusion/READ and association boundary.
	 * @param CardFormatter $formatter Safe source reference.
	 * @param Closure(): PlanState $stateFactory Instance-secret HMAC.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger, private Closure $filesFactory,
		private CardFormatter $formatter, private Closure $stateFactory) {
		parent::__construct($gateway, $logger);
	}


	/**
	 * @param array<string,mixed> $arguments validated file listing arguments
	 * @param string $userId caller UID
	 * @return array<string,mixed> unchanged visible file list
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(fn (): array => $this->files()->list($userId, (int)$arguments['cardId']));
	}


	/** @return CardFiles File access resolved only after the module app gate admits the actual operation. */
	private function files(): CardFiles {
		return $this->access ??= ($this->filesFactory)();
	}

	/**
	 * {@inheritDoc}
	 * @return string unchanged result of this operation
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
