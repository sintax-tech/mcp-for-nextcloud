<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Polls;

use OCA\Mcp\Modules\Polls\Integration\PollsMutationSnapshot;
use OCA\Mcp\Modules\Polls\Integration\PollsOptionValues;
use OCA\Mcp\Modules\Polls\Integration\PollsShareCreator;

/** Version gaps of the native mutation API; each has its real 8/9 fallback. */
final class PollsMutationApiUsage {
	/** Exact missing releases and the only production caller handling each gap. */
	public const VERSION_GATED = [
		'OCA\\Polls\\Model\\DateTimeImmutable' => [
			'missing' => ['31', '32'], 'fallback' => 'OCA\\Polls\\Db\\Option::setOption',
			'by' => PollsOptionValues::class,
			'elsewhere' => ['Modules/Polls/Integration/PollsMutationSurface.php' => 'Reflection only, 9.x branch checked by the installation probe.'],
		],
		'OCA\\Polls\\Model\\DateInterval' => [
			'missing' => ['31', '32'], 'fallback' => 'OCA\\Polls\\Db\\Option::setOption',
			'by' => PollsOptionValues::class,
			'elsewhere' => ['Modules/Polls/Integration/PollsMutationSurface.php' => 'Reflection only, 9.x branch checked by the installation probe.'],
		],
		'OCA\\Polls\\Db\\Option::setDateTime' => [
			'missing' => ['31', '32'], 'fallback' => 'OCA\\Polls\\Db\\Option::setOption', 'by' => PollsOptionValues::class,
		],
		'OCA\\Polls\\Db\\Option::setOption' => [
			'missing' => ['33', '34', '35'], 'fallback' => 'OCA\\Polls\\Db\\Option::setDateTime', 'by' => PollsOptionValues::class,
		],
		'OCA\\Polls\\Db\\UserMapper::createUserObject' => [
			'missing' => ['31', '32'], 'fallback' => 'OCA\\Polls\\Db\\UserMapper::getUserObject', 'by' => PollsMutationSnapshot::class,
		],
		'OCA\\Polls\\Db\\UserMapper::getUserObject' => [
			'missing' => ['33', '34', '35'], 'fallback' => 'OCA\\Polls\\Db\\UserMapper::createUserObject', 'by' => PollsMutationSnapshot::class,
		],
		'OCA\\Polls\\Service\\ShareService::createPublicShare' => [
			'missing' => ['31', '32'], 'fallback' => 'OCA\\Polls\\Service\\ShareService::add', 'by' => PollsShareCreator::class,
		],
		'OCA\\Polls\\Service\\ShareService::createShare' => [
			'missing' => ['31', '32'], 'fallback' => 'OCA\\Polls\\Service\\ShareService::add', 'by' => PollsShareCreator::class,
			'elsewhere' => [
				'Modules/Files/Sharing/ShareWriter.php' => 'OCP file shares, not Polls.',
				'Modules/Deck/Integration/NativeCardFileLink.php' => 'OCP file shares, not Polls.',
				'Modules/Talk/Attachments/FileSharer.php' => 'OCP file shares, not Polls.',
			],
		],
	];
	/** Same method names on unrelated native value objects and services. */
	public const NAME_CLASHES = [
		'OCA\\Polls\\Model\\SimpleOption::setDateTime' => 'Only Db Option is updated; SimpleOption is constructed exclusively with fromArray.',
	];
}
