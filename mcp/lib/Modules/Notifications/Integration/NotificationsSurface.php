<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Integration;

use ReflectionMethod;
use Throwable;

/** Lazy signature guard for the native read and the one-id dismiss path shared by the NC34/35 pins. */
final class NotificationsSurface {
	/** Native storage owner; never instantiated during discovery. */
	public const HANDLER = 'OCA\\Notifications\\Handler';
	/** Native device push used by the dismiss endpoint after a successful delete. */
	public const PUSH = 'OCA\\Notifications\\Push';
	/** Exception the native getById throws for a missing row and for another user's id. */
	public const NOT_FOUND = 'OCA\\Notifications\\Exceptions\\NotificationNotFoundException';
	/** @param string $handler native class override for source-shape tests */
	public function __construct(private string $handler = self::HANDLER) {}
	/** @return bool exact read prefix, without calling constructors, notifiers or the database */
	public function supported(): bool {
		try {
			if (!class_exists($this->handler)) return false;
			$get = new ReflectionMethod($this->handler, 'get');
			if (!$get->isPublic() || $get->isStatic() || $get->returnsReference() || (string)$get->getReturnType() !== 'array'
				|| $get->getNumberOfRequiredParameters() !== 1 || count($get->getParameters()) !== 2) return false;
			foreach (['OCP\\Notification\\INotification', 'int'] as $index => $type) {
				$p = $get->getParameters()[$index];
				if ((string)$p->getType() !== $type || $p->isPassedByReference() || $p->isVariadic()) return false;
			}
			return true;
		} catch (Throwable) { return false; }
	}

	/**
	 * Whether one owned notification can be dismissed the way the native endpoint does.
	 *
	 * The list capability stays valid without this. Push and the not-found exception are required only here.
	 *
	 * @return bool exact getById, deleteById and pushDeleteToDevice prefixes, without constructing them
	 */
	public function dismissSupported(): bool {
		try {
			if (!class_exists($this->handler) || !class_exists(self::PUSH) || !class_exists(self::NOT_FOUND)) {
				return false;
			}
			return $this->signature($this->handler, 'getById', 'OCP\\Notification\\INotification', [
				['int', false], ['string', false],
			], 2)
				&& $this->signature($this->handler, 'deleteById', 'bool', [
					['int', false], ['string', false], ['OCP\\Notification\\INotification', true],
				], 2)
				&& $this->signature(self::PUSH, 'pushDeleteToDevice', 'void', [
					['string', false], ['array', true], ['string', false],
				], 2);
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * @param string $class native class
	 * @param string $method public instance method
	 * @param string $return named return type, not null
	 * @param list<array{0:string,1:bool}> $parameters type name and whether it accepts null
	 * @param int $required number of required parameters
	 * @return bool whether the reflected signature matches
	 */
	private function signature(string $class, string $method, string $return, array $parameters, int $required): bool {
		$ref = new ReflectionMethod($class, $method);
		$type = $ref->getReturnType();
		if (!$ref->isPublic() || $ref->isStatic() || $ref->returnsReference() || !$type instanceof \ReflectionNamedType
			|| $type->getName() !== $return || $type->allowsNull() || $ref->getNumberOfRequiredParameters() !== $required
			|| count($ref->getParameters()) !== count($parameters)) {
			return false;
		}
		foreach ($parameters as $index => [$name, $null]) {
			$parameter = $ref->getParameters()[$index];
			$parameterType = $parameter->getType();
			if (!$parameterType instanceof \ReflectionNamedType || $parameterType->getName() !== $name || $parameterType->allowsNull() !== $null
				|| $parameter->isPassedByReference() || $parameter->isVariadic()) {
				return false;
			}
		}
		return true;
	}
}
