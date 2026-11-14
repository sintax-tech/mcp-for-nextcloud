<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Integration;

use ReflectionMethod;
use Throwable;

/** Lazy inspection of the select-only mapper read. Nothing native is constructed. */
final class StatusSurface {
	/** Native mapper; resolved only after admission. */
	public const MAPPER = 'OCA\\UserStatus\\Db\\UserStatusMapper';

	/** @param string $mapper mapper class; a test may pass a stand-in */
	public function __construct(private string $mapper = self::MAPPER) {
	}

	/** @return bool the public select signature is present and nothing was instantiated */
	public function supported(): bool {
		try {
			if (!class_exists($this->mapper)) {
				return false;
			}
			$method = new ReflectionMethod($this->mapper, 'findByUserId');
			$parameters = $method->getParameters();
			if (!$method->isPublic() || $method->isStatic() || $method->returnsReference()
				|| $method->getNumberOfRequiredParameters() !== 1 || count($parameters) !== 2) {
				return false;
			}
			if ((string)$parameters[0]->getType() !== 'string' || !$parameters[1]->isOptional()
				|| (string)$parameters[1]->getType() !== 'bool') {
				return false;
			}
			foreach ($parameters as $parameter) {
				if ($parameter->isPassedByReference() || $parameter->isVariadic()) {
					return false;
				}
			}
			return (string)$method->getReturnType() === 'OCA\\UserStatus\\Db\\UserStatus';
		} catch (Throwable) {
			return false;
		}
	}
}
