<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use Closure;
use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\Util;

/**
 * Installation-wide probe of the native Forms structure API (questions/options add, reorder, delete and type change).
 *
 * It reads only the app version, the Nextcloud major and class/method/constant existence, without building any Forms
 * service, touching the database, the network or a session. Exceptions are turned into false by the CapabilityRegistry.
 */
final class StructureCapabilities implements CapabilityProbe {
	/** Capability key the seven structure tools require. */
	public const KEY = 'forms.native_structure_api';
	/** Forms versions whose ApiController bodies are pinned by hash in the contract fixtures. */
	public const PROVEN = ['5.3.0', '5.3.7', '5.4.0', '5.4.1'];
	/** Nextcloud majors the four pins are supported on (Forms 5.3/5.4 declare NC 32–35). */
	public const MAJORS = [32, 33, 34, 35];
	/** Native members the structure flow calls, by class. */
	private const MEMBERS = [
		'OCA\\Forms\\Controller\\ApiController' => ['newQuestion', 'reorderQuestions', 'newOption', 'reorderOptions', 'deleteQuestion', 'deleteOption', 'updateQuestion'],
		'OCA\\Forms\\Service\\FormsService' => ['getFormIfAllowed', 'isFormArchived', 'areExtraSettingsValid'],
	];

	private ?Closure $nativeProbe;

	/**
	 * @param IAppManager $apps app manager, for the version and the enablement
	 * @param int|null $nextcloudMajor Nextcloud major, read from the server when null
	 * @param callable|null $nativeProbe test seam: fn(string $class, string $member): bool
	 */
	public function __construct(private IAppManager $apps, private ?int $nextcloudMajor = null, ?callable $nativeProbe = null) {
		$this->nativeProbe = $nativeProbe === null ? null : Closure::fromCallable($nativeProbe);
	}

	/** {@inheritDoc} */
	public function keys(): array {
		return [self::KEY];
	}

	/** {@inheritDoc} */
	public function supports(string $key): bool {
		if ($key !== self::KEY) {
			return false;
		}
		$version = (string)$this->apps->getAppVersion('forms');
		$major = $this->nextcloudMajor ?? (int)Util::getVersion()[0];
		if (!in_array($version, self::PROVEN, true) || !in_array($major, self::MAJORS, true) || !AppEnablement::forAnyone($this->apps, 'forms')) {
			return false;
		}
		$probe = $this->nativeProbe ?? static fn (string $class, string $member): bool => $member === 'ANSWER_TYPES'
			? defined($class . '::' . $member) : method_exists($class, $member);
		foreach (self::MEMBERS as $class => $members) {
			foreach ($members as $member) {
				if (!$probe($class, $member)) {
					return false;
				}
			}
		}
		return $probe('OCA\\Forms\\Constants', 'ANSWER_TYPES');
	}
}
