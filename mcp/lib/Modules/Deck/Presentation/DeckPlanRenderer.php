<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;


class DeckPlanRenderer {
    public function __construct(private ?\DateTimeZone $zone = null) {
    }

    /**
     * @param string $tool Tool name
     * @param array<string, mixed> $plan Write plan
     * @return string|null Markdown body, or null to fall back to generic
     */
    public function render(string $tool, array $plan): ?string {
        try {
            $zone = PlanText::resolveZone($plan, $this->zone);
            return match ($tool) {
                'deck_create_card' => CardPlanRenderer::renderCreate($plan, $zone),
                'deck_edit_card' => CardPlanRenderer::renderEdit($plan, $zone),
                'deck_move_card' => CardPlanRenderer::renderMove($plan),
                'deck_delete_card' => CardPlanRenderer::renderDelete($plan),
                'deck_create_board' => StructurePlanRenderer::renderCreateBoard($plan, $zone),
                'deck_create_stack' => StructurePlanRenderer::renderCreateStack($plan),
                'deck_delete_stack' => StructurePlanRenderer::renderDeleteStack($plan),
                'deck_delete_board' => StructurePlanRenderer::renderDeleteBoard($plan),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }
}
