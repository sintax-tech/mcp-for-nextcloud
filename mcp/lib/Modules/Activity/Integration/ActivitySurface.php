<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Integration;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

/** Lazy inspection of the shared Activity 7/8 read prefix and the collector's override contract. */
final class ActivitySurface {
	/** Native data owner; resolved only after admission. */
	public const DATA = 'OCA\\Activity\\Data';
	/** Native preferences owner. */
	public const SETTINGS = 'OCA\\Activity\\UserSettings';
	/** Native helper is subclassed only after this shape has been proven. */
	public const GROUP = 'OCA\\Activity\\GroupHelper';
	/**
	 * @param string $data data class
	 * @param string $group collector parent
	 * @param string $settings preference class; overrides are source-contract test seams
	 */
	public function __construct(private string $data = self::DATA, private string $group = self::GROUP, private string $settings = self::SETTINGS) {}
	/** @return bool no native constructor, SQL, provider or notification runs during discovery */
	public function supported(): bool {
		try {
			foreach ([$this->data, $this->settings, $this->group] as $class) if (!class_exists($class)) return false;
			$group = new ReflectionClass($this->group);
			if ($group->isFinal() || $group->isAbstract() || $group->isReadOnly() || $group->getConstructor()?->isFinal()) return false;
			foreach (['addActivity' => ['array'], 'getActivities' => []] as $name => $types) {
				$method = $group->getMethod($name);
				if (!$method->isPublic() || $method->isStatic() || $method->isFinal() || $method->returnsReference()) return false;
				if (array_map(static fn ($p) => (string)$p->getType(), $method->getParameters()) !== $types) return false;
				foreach ($method->getParameters() as $parameter) if ($parameter->isPassedByReference() || $parameter->isVariadic()) return false;
				if ((string)$method->getReturnType() !== ($name === 'addActivity' ? 'void' : 'array')) return false;
			}
			$get = new ReflectionMethod($this->data, 'get');
			$parameters = $get->getParameters();
			$types = [self::GROUP, self::SETTINGS, 'string', 'int', 'int', 'string', 'string', 'string', 'int', 'bool'];
			if (!$get->isPublic() || $get->isStatic() || $get->getNumberOfRequiredParameters() !== 7 || (string)$get->getReturnType() !== 'array'
				|| !in_array(count($parameters), [10, 11], true)) return false;
			foreach ($types as $index => $type) if ((string)$parameters[$index]->getType() !== $type || $parameters[$index]->isPassedByReference() || $parameters[$index]->isVariadic()) return false;
			if (count($parameters) === 11 && (!$parameters[10]->isOptional() || !$parameters[10]->allowsNull())) return false;
			$cursor = new ReflectionMethod($this->data, 'getById');
			if (!$cursor->isPublic() || $cursor->isStatic() || $cursor->returnsReference() || $cursor->getNumberOfRequiredParameters() !== 1
				|| count($cursor->getParameters()) !== 1 || (string)$cursor->getReturnType() !== '?OCP\\Activity\\IEvent') return false;
			$parameter = $cursor->getParameters()[0];
			if ((string)$parameter->getType() !== 'int' || $parameter->isPassedByReference() || $parameter->isVariadic()) return false;
			return true;
		} catch (Throwable) { return false; }
	}
}
