<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Share\IManager as IShareManager;

/** Validates share dates in the user timezone. */
final class ShareExpiry {
	/** Noon keeps the selected date when converted to the server timezone (within ±12 h). */
	public const HOUR = '12:00:00';

	/**
	 * Constructs the sharing collaborator.
	 *
	 * @param IShareManager $shareManager Public expiry settings.
	 * @param ITimeFactory $time Current time source.
	 */
	public function __construct(
		private IShareManager $shareManager,
		private ITimeFactory $time,
	) {}

	/**
	 * The last day of the share, as the user wrote it: a real date of the calendar, from tomorrow in the user's zone,
	 * and within the administrator's maximum when one is enforced.
	 *
	 * @param string $date Y-m-d
	 * @param \DateTimeZone $zone the user's zone
	 * @param bool $link whether the maximum is the one of public links instead of the one of people and groups
	 * @return \DateTime noon of that day in the user's zone, the instant handed to the core
	 * @throws ArgumentValidationException with field `expires` and the rule broken
	 */
	public function expiry(string $date, \DateTimeZone $zone, bool $link = false): \DateTime {
		$parsed = \DateTime::createFromFormat('!Y-m-d', $date, $zone);
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new ArgumentValidationException('Invalid argument: expires', 'expires', Translator::t('expected a date as YYYY-MM-DD'));
		}
		$today = $this->today($zone);
		if ($date <= $today->format('Y-m-d')) {
			throw new ArgumentValidationException('Invalid argument: expires', 'expires', Translator::t('tomorrow or later'));
		}
		$enforced = $link ? $this->shareManager->shareApiLinkDefaultExpireDateEnforced() : $this->shareManager->shareApiInternalDefaultExpireDateEnforced();
		if ($enforced) {
			$days = $link ? $this->shareManager->shareApiLinkDefaultExpireDays() : $this->shareManager->shareApiInternalDefaultExpireDays();
			if ($date > $today->modify('+' . $days . ' days')->format('Y-m-d')) {
				throw new ArgumentValidationException('Invalid argument: expires', 'expires',
					Translator::t('at most %s days from today (administrator rule)', [$days]));
			}
		}
		return new \DateTime($date . ' ' . self::HOUR, $zone);
	}

	/**
	 * @param \DateTimeZone $zone the user's zone
	 * @return \DateTimeImmutable midnight of today in the user's zone
	 */
	public function today(\DateTimeZone $zone): \DateTimeImmutable {
		return (new \DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($zone)->setTime(0, 0);
	}
}
