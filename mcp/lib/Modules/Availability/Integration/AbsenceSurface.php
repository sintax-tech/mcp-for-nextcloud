<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Integration;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

/** Lazy inspection of the DAV absence read path verified in server 34.0.4 and 35.0.1; nothing is built. */
final class AbsenceSurface {
	/** Native absence service; only getAbsence and isInEffect are called. */
	public const SERVICE = 'OCA\\DAV\\Service\\AbsenceService';
	/** Native absence entity returned by getAbsence. */
	public const ENTITY = 'OCA\\DAV\\Db\\Absence';
	/** Native time zone resolution used by AbsenceService for the same absence. */
	public const TIMEZONE = 'OCA\\DAV\\CalDAV\\TimezoneService';
	/** Public feature switch and cache owner; only isEnabled is called. */
	public const COORDINATOR = 'OCP\\User\\IAvailabilityCoordinator';
	/** Public out-of-office data returned by the entity conversion. */
	public const DATA = 'OCP\\User\\IOutOfOfficeData';
	/** Stored entity fields read through the native Entity getters. */
	private const FIELDS = ['userId', 'firstDay', 'lastDay', 'status', 'message', 'replacementUserId', 'replacementUserDisplayName'];
	/**
	 * @param string $service service class
	 * @param string $entity entity class
	 * @param string $timezone time zone class; overrides are source-contract test seams
	 */
	public function __construct(private string $service = self::SERVICE, private string $entity = self::ENTITY, private string $timezone = self::TIMEZONE) {}
	/** @return bool every native signature the read relies on is present; no constructor, SQL or cache runs */
	public function supported(): bool {
		try {
			foreach ([$this->service, $this->entity, $this->timezone] as $class) if (!class_exists($class)) return false;
			if (!interface_exists(self::COORDINATOR) || !interface_exists(self::DATA)) return false;
			return $this->method($this->service, 'getAbsence', ['string'], '?' . $this->entity)
				&& $this->method($this->service, 'isInEffect', [self::DATA], 'bool')
				&& $this->method($this->entity, 'toOutOufOfficeData', ['OCP\\IUser', 'string'], self::DATA)
				&& $this->method($this->timezone, 'getUserTimezone', ['string'], '?string')
				&& $this->method($this->timezone, 'getDefaultTimezone', [], 'string')
				&& $this->method(self::COORDINATOR, 'isEnabled', [], 'bool')
				&& $this->fields();
		} catch (Throwable) { return false; }
	}
	/**
	 * @param string $class owner
	 * @param string $name method
	 * @param list<string> $types exact parameter types
	 * @param string $return exact return type
	 * @return bool public instance method with exactly this signature
	 */
	private function method(string $class, string $name, array $types, string $return): bool {
		$method = new ReflectionMethod($class, $name);
		if (!$method->isPublic() || $method->isStatic() || $method->returnsReference() || (string)$method->getReturnType() !== $return) return false;
		if (count($method->getParameters()) !== count($types) || $method->getNumberOfRequiredParameters() !== count($types)) return false;
		foreach ($method->getParameters() as $index => $parameter) {
			if ((string)$parameter->getType() !== $types[$index] || $parameter->isPassedByReference() || $parameter->isVariadic()) return false;
		}
		return true;
	}
	/** @return bool the entity still declares every stored field the projection reads */
	private function fields(): bool {
		$entity = new ReflectionClass($this->entity);
		foreach (self::FIELDS as $field) if (!$entity->hasProperty($field)) return false;
		return $entity->isSubclassOf('OCP\\AppFramework\\Db\\Entity');
	}
}
