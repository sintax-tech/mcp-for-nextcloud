<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use Closure;

/**
 * One account/installation state that discovery is frozen under. The named constructors are the scenarios of the
 * RE-01 ticket; everything not named keeps the default: every grant of the catalog allowed, every optional app on,
 * not a guest, Deck with its dependency API.
 */
final class DiscoveryScenario {
	/** Group whose policy is indeterminate (needs_confirm) in {@see self::unknownGrant()}. */
	public const PAUSED_GROUP = 'paused';
	/** Apps Guests allows the guest of {@see self::guest()}: Files is always allowed by Guests, Talk is on the list. */
	public const GUEST_APPS = ['files', 'spreed'];
	/** Grants only the group of {@see self::unknownGrant()} holds, so they resolve to unknown for alice. */
	public const UNKNOWN_GRANTS = ['notes' => ['create'], 'files' => ['share', 'link']];

	/**
	 * @param string $name file-name part of the scenario's golden
	 * @param bool $optionalApps whether every optional app answers enabled for the user
	 * @param list<string>|null $guestApps apps Guests allows; null when the account is not a guest
	 * @param bool $deckDependencies whether the Deck probe reports the dependency API
	 * @param (Closure(string, string): bool)|null $grants which catalog grants alice holds; null: all of them
	 * @param array<string, list<string>> $unknownGrants grants only an indeterminate group holds (left unset for alice)
	 * @param bool $collectivesNative whether the Collectives probe reports the proven native shape
	 */
	private function __construct(
		public readonly string $name,
		public readonly bool $optionalApps = true,
		public readonly ?array $guestApps = null,
		public readonly bool $deckDependencies = true,
		public readonly ?Closure $grants = null,
		public readonly array $unknownGrants = [],
		public readonly bool $collectivesNative = true,
	) {
	}

	/** @return self every optional app on, every grant allowed */
	public static function allOn(): self {
		return new self('all-on');
	}

	/** @return self every optional app off (only Files and the core tools stay) */
	public static function optionalAppsOff(): self {
		return new self('optional-apps-off', optionalApps: false);
	}

	/** @return self a guest account restricted by Guests to {@see self::GUEST_APPS} */
	public static function guest(): self {
		return new self('guest', guestApps: self::GUEST_APPS);
	}

	/** @return self Deck without the native dependency API: its three dependency tools disappear */
	public static function deckWithoutDependencies(): self {
		return new self('deck-without-dependencies', deckDependencies: false);
	}

	/** @return self an account whose only grant is files.link: files_share/files_unshare via grantAnyOf */
	public static function filesLinkOnly(): self {
		return new self('files-link-only', grants: static fn (string $module, string $operation): bool => $module === 'files' && $operation === 'link');
	}

	/** @return self grants of an indeterminate group: those tools alone leave the listing */
	public static function unknownGrant(): self {
		return new self('unknown-grant', unknownGrants: self::UNKNOWN_GRANTS);
	}

	/** @return self Collectives enabled but outside the proven version or shape: its tools disappear */
	public static function collectivesUnsupported(): self {
		return new self('collectives-unsupported', collectivesNative: false);
	}

	/** @return list<self> every scenario of the ticket, in golden order */
	public static function all(): array {
		return [self::allOn(), self::optionalAppsOff(), self::guest(), self::deckWithoutDependencies(), self::filesLinkOnly(), self::unknownGrant(), self::collectivesUnsupported()];
	}

	/**
	 * @param string $module catalog module
	 * @param string $operation catalog operation
	 * @return bool|null the individual grant of alice: true/false, or null to leave it unset
	 */
	public function grant(string $module, string $operation): ?bool {
		if (in_array($operation, $this->unknownGrants[$module] ?? [], true)) {
			return null;
		}
		return $this->grants === null || ($this->grants)($module, $operation);
	}
}
