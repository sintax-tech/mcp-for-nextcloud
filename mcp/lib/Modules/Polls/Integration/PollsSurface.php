<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

use OCA\Mcp\Modules\Polls\PollsRefusal;

/**
 * Checks only the native public surfaces PO-1 actually calls. A matching shape is permission to try under the same
 * guards, not a semantic proof for an unpinned future release. No private property or container binding is changed.
 */
final class PollsSurface {
	/** Native classes referenced lazily, after enablement. */
	public const SESSION = 'OCA\\Polls\\UserSession';
	/** Native poll service. */
	public const POLLS = 'OCA\\Polls\\Service\\PollService';
	/** Native options service, freshly constructed. */
	public const OPTIONS = 'OCA\\Polls\\Service\\OptionService';
	/** Native poll entity. */
	public const POLL = 'OCA\\Polls\\Db\\Poll';
	/** Native option entity. */
	public const OPTION = 'OCA\\Polls\\Db\\Option';
	/** Native identity model, not an arbitrary session identifier. */
	public const USER = 'OCA\\Polls\\Model\\UserBase';
	/** Native global resolver which the entities themselves use. */
	public const CONTAINER = 'OCA\\Polls\\Helper\\Container';
	/** Ordered native OptionService constructor dependencies. */
	public const DEPENDENCIES = [
		'OCP\\EventDispatcher\\IEventDispatcher', 'Psr\\Log\\LoggerInterface',
		'OCA\\Polls\\Db\\OptionMapper', 'OCA\\Polls\\Db\\PollMapper',
		self::SESSION, self::POLL, 'OCA\\Polls\\Service\\VoteService',
	];

	/**
	 * Refuses unknown shapes before a native domain read.
	 * @throws PollsRefusal when a class, constant, signature or return type is unsupported
	 */
	public function assertSupported(): void {
		foreach ([self::SESSION, self::POLLS, self::OPTIONS, self::POLL, self::OPTION, self::USER, self::CONTAINER] as $class) {
			if (!class_exists($class)) {
				throw PollsRefusal::unavailable();
			}
		}
		foreach (['cleanSession' => 'void', 'getCurrentUser' => self::USER, 'getCurrentUserId' => 'string', 'getIsLoggedIn' => 'bool', 'getShareToken' => 'string'] as $method => $return) {
			$this->assertMethod(self::SESSION, $method, [], $return);
		}
		$this->assertMethod(self::POLLS, 'listPolls', [], 'array');
		$this->assertMethod(self::POLLS, 'get', ['int'], [self::POLL, '']);
		$this->assertMethod(self::OPTIONS, 'list', ['int'], 'array');
		$this->assertMethod(self::POLL, 'getIsAllowed', ['string'], 'bool');
		$this->assertMethod(self::POLL, 'getVoteUrl', [], 'string');
		$this->assertMethod(self::POLL, 'jsonSerialize', [], 'array');
		$this->assertMethod(self::OPTION, 'jsonSerialize', [], 'array');
		$this->assertMethod(self::USER, 'getId', [], 'string');
		$this->assertMethod(self::USER, 'getType', [], 'string');
		$this->assertMethod(self::CONTAINER, 'queryClass', ['string'], 'mixed', true);
		$this->assertMethod(self::POLL, '__construct', [], null);
		$this->assertMethod(self::OPTIONS, '__construct', self::DEPENDENCIES, null);
		foreach ([self::POLL . '::PERMISSION_POLL_ACCESS', self::USER . '::TYPE_USER', self::USER . '::TYPE_ADMIN'] as $name) {
			if (!defined($name) || !is_string(constant($name)) || constant($name) === '') {
				throw PollsRefusal::unavailable();
			}
		}
	}

	/**
	 * Verifies the exact required argument and return shape without inspecting private internals.
	 * @param string $class native class name
	 * @param string $name public method
	 * @param list<string> $parameters native parameter types in order
	 * @param string|list<string>|null $returns allowed native return types; untyped PollService::get exists in 8.6.3 and its actual entity is checked
	 * @param bool $static whether the native method is static
	 * @throws PollsRefusal when the signature is different
	 */
	public function assertMethod(string $class, string $name, array $parameters, string|array|null $returns, bool $static = false): void {
		$method = new \ReflectionMethod($class, $name);
		if (!$method->isPublic() || $method->isStatic() !== $static || $method->getNumberOfParameters() !== count($parameters)
			|| $method->getNumberOfRequiredParameters() > count($parameters)
			|| ($returns !== null && !in_array((string)$method->getReturnType(), (array)$returns, true))) {
			throw PollsRefusal::unavailable();
		}
		foreach ($method->getParameters() as $index => $parameter) {
			if ((string)$parameter->getType() !== $parameters[$index] || $parameter->isVariadic() || $parameter->isPassedByReference()) {
				throw PollsRefusal::unavailable();
			}
		}
	}
}
