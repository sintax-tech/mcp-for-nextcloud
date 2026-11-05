<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Modules\Mail\MailUnavailableException;

/** Constructs native search values after the actor enablement check. */
final class MailSearchValues {
	/** OCA\Mail\Service\Search\SearchQuery: the filter value object the Mail mappers take. */
	private const SEARCH_QUERY_CLASS = 'OCA\\Mail\\Service\\Search\\SearchQuery';

	/** OCA\Mail\Service\Search\Flag: a flag condition of a search query. */
	private const FLAG_CLASS = 'OCA\\Mail\\Service\\Search\\Flag';

	/** Static native value construction only. */
	private function __construct() {}

	/**
	 * A new, empty OCA\Mail\Service\Search\SearchQuery.
	 *
	 * @param bool $enabled whether the Mail app is enabled for the actor on this call
	 * @return object OCA\Mail\Service\Search\SearchQuery
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public static function newSearchQuery(bool $enabled): object {
		$class = self::SEARCH_QUERY_CLASS;
		if (!$enabled || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		return new $class();
	}

	/**
	 * A flag condition of a search query: Flag::is() or Flag::not() of one of the Mail flag constants.
	 *
	 * @param bool $enabled whether the Mail app is enabled for the actor on this call
	 * @param string $constant name of the Flag constant: SEEN, FLAGGED or DELETED
	 * @param bool $isSet true for "has the flag", false for "has not"
	 * @return object OCA\Mail\Service\Search\Flag
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public static function flag(bool $enabled, string $constant, bool $isSet): object {
		$class = self::FLAG_CLASS;
		if (!$enabled || !class_exists($class) || !defined($class . '::' . $constant)) {
			throw new MailUnavailableException();
		}
		$value = constant($class . '::' . $constant);
		return $isSet ? $class::is($value) : $class::not($value);
	}

}
