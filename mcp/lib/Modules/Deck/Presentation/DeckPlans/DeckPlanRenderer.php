<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation\DeckPlans;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Dispatches the existing plan text in the timezone carried by the plan. */
final class DeckPlanRenderer extends DeckPlanValues {
	/**
	 * @param ?\DateTimeZone $zone timezone carried by the plan
	 */
	public function __construct(private ?\DateTimeZone $zone = null) {
	}

	/**
	 * Dispatch an authorized Deck plan to its focused human-readable renderer.
	 *
	 * @param string $tool Tool name
	 * @param array<string, mixed> $plan Write plan
	 * @return string|null Markdown body, or null to fall back to generic
	 */
	public function render(string $tool, array $plan): ?string {
		try {
			$zone = $this->resolveZone($plan);
			return match ($tool) {
				'deck_link_card_file' => \OCA\Mcp\Modules\Deck\Attachments\CardLinkPlan::render($plan),
				'deck_unlink_card_file' => $this->renderUnlinkFile($plan),
				'deck_add_card_dependency', 'deck_remove_card_dependency' => $this->renderDependency($plan),
				'deck_edit_card_labels' => $this->renderLabels($plan),
				'deck_create_card' => (new CardPlanText())->renderCreate($plan, $zone),
				'deck_edit_card' => (new CardPlanText())->renderEdit($plan, $zone),
				'deck_move_card' => (new CardPlanText())->renderMove($plan),
				'deck_delete_card' => (new CardPlanText())->renderDelete($plan),
				'deck_create_board' => (new StructurePlanText())->renderCreateBoard($plan, $zone),
				'deck_create_stack' => (new StructurePlanText())->renderCreateStack($plan),
				'deck_delete_stack' => (new StructurePlanText())->renderDeleteStack($plan),
				'deck_delete_board' => (new StructurePlanText())->renderDeleteBoard($plan),
				default => null,
			};
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $plan Visible file and creator, authorized before formatting.
	 * @return string Escaped file identity, source, creator and global share-revocation warning.
	 */
	private function renderUnlinkFile(array $plan): string {
		return Translator::t('Unlink %s from Deck card %s.', [PlanText::em($plan['file']['name']), PlanText::em($plan['card']['title'])])
			. "\n\n" . Translator::t('Association created by: %s', [PlanText::inline($plan['createdBy'])])
			. "\n\n" . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::fileUnlinkWarning()
			. ($this->sharedLine($plan['board']) === [] ? '' : "\n\n" . implode("\n", $this->sharedLine($plan['board'])));
	}

	/**
	 * @param array<string, mixed> $plan Requested readable edge and before/after presence.
	 * @return string Escaped direction and state, with the visibility/concurrency limitation.
	 */
	private function renderDependency(array $plan): string {
		$state = static fn (bool $present): string => $present ? Translator::t('present') : Translator::t('absent');
		return Translator::t('Deck dependency: %s → %s.', [PlanText::em($plan['origin']['title']), PlanText::em($plan['target']['title'])])
			. "\n\n" . Translator::t('Before: %s', [$state($plan['before'])])
			. "\n\n" . Translator::t('After: %s', [$state($plan['after'])])
			. "\n\n" . \OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependencyWriteLimitations()
			. ($this->sharedLine($plan['board']) === [] ? '' : "\n\n" . implode("\n", $this->sharedLine($plan['board'])));
	}

	/**
	 * Renders the card/board identity and visible before/after labels.
	 * @return string Escaped confirmation body.
	 *
	 * @param array<string, mixed> $plan
	 */
	private function renderLabels(array $plan): string {
		$labels = static fn (array $items): string => $items === [] ? '—' : implode(', ', array_map(
			static fn (array $label): string => PlanText::inline($label['title']) . ' (' . (int)$label['id'] . ')', $items));
		return Translator::t('Change labels on %s in %s.', [PlanText::em($plan['card']['title']), PlanText::em($plan['board']['name'])])
			. "\n\n" . Translator::t('Before: %s', [$labels($plan['before'])])
			. "\n\n" . Translator::t('After: %s', [$labels($plan['after'])])
			. "\n\n" . \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planLabels()
			. ($this->sharedLine($plan['board']) === [] ? '' : "\n\n" . implode("\n", $this->sharedLine($plan['board'])));
	}

	/**
	 * Resolves the timezone from the plan, constructor or system default.
	 * @param array<string, mixed> $plan Confirmation plan.
	 * @return \DateTimeZone Timezone used for display.
	 */
	private function resolveZone(array $plan): \DateTimeZone {
		if (isset($plan['timezone']) && is_string($plan['timezone']) && $plan['timezone'] !== '') {
			try {
				return new \DateTimeZone($plan['timezone']);
			} catch (\Throwable) {
				// fall through
			}
		}
		if ($this->zone !== null) {
			return $this->zone;
		}
		return new \DateTimeZone(date_default_timezone_get());
	}
}
