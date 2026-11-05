<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use Closure;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Service\CardService;
use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\Util;

/** Fail-closed, lazy native API detection; no Deck service is instantiated for catalogue discovery. */
final class DeckCapabilities implements CapabilityProbe {
	/** Exact releases proved in containers; newer patches still require the matching line and native shape. */
	public const PROVEN = [34 => '1.18.5', 35 => '1.19.0'];

	private ?Closure $nativeProbe;

	/**
	 * @param IAppManager $apps Installed/enabled Deck version.
	 * @param int|null $nextcloudMajor Server major, null to read the real public version API.
	 * @param callable(string, string): bool|null $nativeProbe Test seam; default checks native methods/properties.
	 */
	public function __construct(private IAppManager $apps, private ?int $nextcloudMajor = null,
		?callable $nativeProbe = null) {
		$this->nativeProbe = $nativeProbe === null ? null : Closure::fromCallable($nativeProbe);
	}

	/** {@inheritDoc} */
	public function keys(): array {
		return ['deck.dependencies'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * An unknown key is false. The dependency key calls {@see self::dependencies()} and does not catch what it throws.
	 */
	public function supports(string $key): bool {
		if ($key !== 'deck.dependencies') {
			return false;
		}

		return $this->dependencies();
	}

	/** @return bool Whether this compatible installation exposes the native dependency API. */
	public function dependencies(): bool {
		$version = (string)$this->apps->getAppVersion('deck');
		if ($version === '') {
			return false;
		}
		$major = $this->nextcloudMajor ?? (int)Util::getVersion()[0];
		$minimum = self::PROVEN[$major] ?? null;
		$maximum = [34 => '1.19.0', 35 => '1.20.0'][$major] ?? null;
		if ($minimum === null || version_compare($version, $minimum, '<')
			|| version_compare($version, $maximum, '>=') || !AppEnablement::forAnyone($this->apps, 'deck')) {
			return false;
		}
		$probe = $this->nativeProbe ?? static fn (string $class, string $member): bool =>
			$member === 'dependentCards' ? property_exists($class, $member) : method_exists($class, $member);
		foreach ([[CardService::class, 'assignDependentCard'], [CardService::class, 'removeDependentCard'],
			[CardMapper::class, 'findDependenciesForCards'], [Card::class, 'dependentCards']] as [$class, $member]) {
			if (!$probe($class, $member)) {
				return false;
			}
		}
		return true;
	}
}
