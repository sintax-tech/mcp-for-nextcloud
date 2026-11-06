<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;

/** Keeps the existing Deck dependency text unchanged. */
final class DeckDependencyMessages {
	/** @return string Localized dependency dependenciesUnavailable message. */
	public static function dependenciesUnavailable(): string {
		return Translator::t('Native Deck dependency reading is unavailable on this installation.');
	}

	/** @return string Localized dependency dependencyGraphLimit message. */
	public static function dependencyGraphLimit(): string {
		return Translator::t('The readable dependency graph exceeds the traversal limits. No incomplete graph was returned.');
	}

	/** @return string Localized dependency dependencyGraphVisibility message. */
	public static function dependencyGraphVisibility(): string {
		return Translator::t('This graph contains only readable cards. Cycles through unreadable cards cannot be detected; a visible graph does not prove that the global graph is acyclic. Native relations do not automatically block task progress.');
	}

	/** @return string Localized dependency change message. */
	public static function dependencyCycle(): string {
		return Translator::t('Self-relations and cycles detectable through readable cards are refused.');
	}

	/** @return string Localized dependency change message. */
	public static function dependencyInactive(): string {
		return Translator::t('Dependency changes require active cards and boards.');
	}

	/** @return string Localized dependency change message. */
	public static function dependencyPlanChanged(): string {
		return Translator::t('The readable dependency state changed. Review the new plan before confirming.')
			. ' ' . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::doesNotProveTheWholeRequest();
	}

	/** @return string Localized dependency change message. */
	public static function dependencyUnconfirmed(): string {
		return Translator::t('The dependency result could not be confirmed. Check the Deck interface; do not repeat the write automatically.')
			. ' ' . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::doesNotProveTheWholeRequest();
	}

	/** @return string Localized dependency change message. */
	public static function dependencyStateObserved(): string {
		return Translator::t('The requested relation state was observed after a failure. Another actor may have produced it; this does not prove which write changed it.')
			. ' ' . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::doesNotProveTheWholeRequest();
	}

	/**
	 * State direction, the readable-cycle refusal and the gap a hidden card leaves.
	 *
	 * @return string Localized dependency change message.
	 */
	public static function dependencyWriteLimitations(): string {
		return Translator::t('Only the requested relation changes. Direction is from this card to the target. Self-relations and cycles through readable cards are refused. Relations to cards you cannot read are omitted, with no count and no id. Cycles through those cards cannot be detected. A concurrent change between the final check and the native write can still create a cycle.');
	}

	/** Constant and translated-message holder. */
	private function __construct() {
	}
}
