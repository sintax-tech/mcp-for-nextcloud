<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Guests;

use OCP\IUser;

/** The lazy Guests operations needed to apply request and module restrictions. */
interface NativeGuests {
	/**
	 * @param IUser|string $user the session user or user id, preserved for the native API
	 * @return bool whether Guests identifies the user as a guest
	 */
	public function isGuest(IUser|string $user): bool;

	/**
	 * @param IUser $user the user of the request
	 * @param string|false $url the request path, as returned by IRequest
	 * @return bool whether Guests allows the requested URL
	 */
	public function isUrlAllowed(IUser $user, string|false $url): bool;

	/**
	 * @param IUser $user the user whose restrictions must be applied
	 * @param bool $session whether that user is the session user
	 * @return bool false when this Guests release cannot restrict a user without a session
	 */
	public function setUp(IUser $user, bool $session): bool;

	/**
	 * @param string $app the app behind the requested module
	 * @return bool whether the list is disabled or the app is allowed by that list
	 */
	public function allowsAppOnList(string $app): bool;

	/** @return bool whether Guests permits external storage */
	public function allowExternalStorage(): bool;
}
