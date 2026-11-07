<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Reading;

use OCA\Mcp\Modules\Deck\Integration\CardFormatter;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Dependencies\DependencyGraph;
use Psr\Log\LoggerInterface;

/** Publishes only the complete readable outgoing graph; there is no hidden total or opaque reference. */
final class ReadCardDependenciesHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	public const TOOL = 'deck_read_card_dependencies';

	/**
	 * @param DeckGatewayInterface $gateway Native READ boundary.
	 * @param LoggerInterface $logger Receives exception classes, never target metadata.
	 * @param CardFormatter $formatter Minimal public card references.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger, private CardFormatter $formatter) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * {@inheritDoc} Limits are applied only to readable nodes and edges, never to raw native IDs.
	 * @param array $arguments validated tool arguments
	 * @param string $userId authenticated caller UID
	 * @return array unchanged result of this operation
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$graph = (new DependencyGraph($this->gateway))->read($userId, (int)$arguments['cardId']);
			$graph['nodes'] = array_map(fn (array $node): array =>
				$this->formatter->reference($node['card'], $node['boardId']), $graph['nodes']);
			return $graph + ['direction' => 'origin.dependentCards contains target',
				'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependencyGraphVisibility()];
		});
	}

	/**
	 * {@inheritDoc}
	 * @return string unchanged result of this operation
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
