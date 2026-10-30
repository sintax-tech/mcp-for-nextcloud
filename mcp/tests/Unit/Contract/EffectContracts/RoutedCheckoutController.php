<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\CheckoutController;

/**
 * The checkout controller with the two seams a unit test needs: the route parameters the router would have put in the
 * URL, and the raw body php://input would have streamed. Nothing else is overridden.
 */
final class RoutedCheckoutController extends CheckoutController {
	/** @var array<string, string> parameters the router put in the URL */
	public array $route = [];
	/** The raw body of the request. */
	public string $staged = '';

	/** @return string|null the token as the URL carried it */
	protected function routeToken(): ?string {
		$token = $this->route['token'] ?? null;
		return is_string($token) && $token !== '' ? $token : null;
	}

	/** @return resource a readable stream over the staged body */
	protected function inputStream() {
		$handle = fopen('php://memory', 'w+');
		fwrite($handle, $this->staged);
		rewind($handle);
		return $handle;
	}
}
