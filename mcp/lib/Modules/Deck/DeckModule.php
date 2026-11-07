<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

/** Publishes Deck through the catalog and delegates its guide and confirmation text. */
final class DeckModule extends \OCA\Mcp\Mcp\Tools\CatalogModule {
	/**
	 * @param \Psr\Container\ContainerInterface $container lazy catalog tool container
	 * @param \OCA\Mcp\Modules\Deck\Presentation\DeckGuideNotes $guide unchanged workflow notes
	 */
	public function __construct(\Psr\Container\ContainerInterface $container, private \OCA\Mcp\Modules\Deck\Presentation\DeckGuideNotes $guide) {
		parent::__construct($container);
	}
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the existing ordered tools */
	public static function catalog(): array {
		return DeckToolCatalog::TOOLS;
	}
	/** @return class-string<DeckRegistration> the existing Deck native boundary */
	public static function registration(): ?string {
		return DeckRegistration::class;
	}
	/** @return list<string> the unchanged Deck workflow notes */
	public function guideNotes(): array {
		return $this->guide->notes();
	}
	/**
	 * @param string $tool registered write tool
	 * @param array<string,mixed> $plan unchanged before/after plan
	 * @return string|null existing confirmation text
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return (new \OCA\Mcp\Modules\Deck\Presentation\DeckPlans\DeckPlanRenderer())->render($tool, $plan);
	}
}
