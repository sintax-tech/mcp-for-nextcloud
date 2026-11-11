<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

/** Exact source-proven write profiles; an unknown release never inherits a neighbouring pin's behavior. */
final class FormsVersionProfiles {
	/** Constructor with mailer and shareMapper. */
	public const LEGACY = '5.3.0';
	/** Constructor with emailValidator and secureRandom. */
	public const VALIDATOR = '5.3.7';
	/** Validator constructor with userConfig as well. */
	public const USER_CONFIG = '5.4';

	/** @var array<string,array{constructorFamily:string,titleFolderSync:bool,updateQuestionAllowsType:bool}> */
	public const PROFILES = [
		'5.3.0' => ['constructorFamily' => self::LEGACY, 'titleFolderSync' => false, 'updateQuestionAllowsType' => true],
		'5.3.7' => ['constructorFamily' => self::VALIDATOR, 'titleFolderSync' => false, 'updateQuestionAllowsType' => true],
		'5.4.0' => ['constructorFamily' => self::USER_CONFIG, 'titleFolderSync' => false, 'updateQuestionAllowsType' => true],
		'5.4.1' => ['constructorFamily' => self::USER_CONFIG, 'titleFolderSync' => true, 'updateQuestionAllowsType' => true],
		'5.4.2' => ['constructorFamily' => self::USER_CONFIG, 'titleFolderSync' => true, 'updateQuestionAllowsType' => false],
	];

	/** Prevents construction of the static profile table. */
	private function __construct() {
	}

	/** Looks up only an exact pin, without admitting unverified versions.
	 * @param string $version Installed Forms release.
	 * @return array{constructorFamily:string,titleFolderSync:bool,updateQuestionAllowsType:bool}|null Proven profile, or null.
	 */
	public static function get(string $version): ?array {
		return self::PROFILES[$version] ?? null;
	}

	/** Requires a proven constructor family before any native dependency resolution.
	 * @param string $version Exact admitted Forms pin.
	 * @return string Constructor family identifier.
	 * @throws \InvalidArgumentException For an unverified release.
	 */
	public static function constructorFamily(string $version): string {
		return self::get($version)['constructorFamily'] ?? throw new \InvalidArgumentException('Unproven Forms version');
	}
}
