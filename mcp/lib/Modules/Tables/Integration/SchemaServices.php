<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCA\Mcp\Modules\Tables\TablesUnavailableException;
use Psr\Container\ContainerInterface;

/** Lazy resolvers for schema/sharing; the existing TablesServices never grows. */
class SchemaServices {
	/**
	 * @param TablesServices $existing Enablement and existing read resolvers.
	 * @param ContainerInterface $container Native app container.
	 * @param TablesSessionGuard $session Caller guard.
	 */
	public function __construct(private TablesServices $existing, private ContainerInterface $container, private TablesSessionGuard $session) {}
	/** @return string Installed Tables version. */
	public function version(): string { return $this->existing->version(); }
	/**
	 * @param string $name Native service/mapper short name.
	 * @param string $uid Caller.
	 * @return object Freshly resolved collaborator.
	 */
	public function get(string $name, string $uid): object {
		$this->session->assertCaller($uid);
		if (!$this->existing->isEnabledFor($uid)) throw new TablesUnavailableException();
		$class = match ($name) {
			'tables' => 'OCA\\Tables\\Service\\TableService', 'views' => 'OCA\\Tables\\Service\\ViewService',
			'columns' => 'OCA\\Tables\\Service\\ColumnService', 'shares' => 'OCA\\Tables\\Service\\ShareService',
			'permissions' => 'OCA\\Tables\\Service\\PermissionsService', 'tableMapper' => 'OCA\\Tables\\Db\\TableMapper',
			'viewMapper' => 'OCA\\Tables\\Db\\ViewMapper', 'columnMapper' => 'OCA\\Tables\\Db\\ColumnMapper',
			'validator' => 'OCA\\Tables\\Validation\\ColumnDtoValidator',
			'circles' => 'OCA\\Tables\\Helper\\CircleHelper',
		};
		if (!class_exists($class)) throw new TablesUnavailableException();
		return str_ends_with($name, 'Mapper') || $name === 'permissions' ? $this->fresh($class, $uid) : $this->container->get($class);
	}
	/**
	 * @param class-string $class Native read boundary.
	 * @param string $uid Authenticated HTTP actor.
	 * @return object Uncached mapper/permission reader.
	 */
	private function fresh(string $class, string $uid): object {
		$reflection = new \ReflectionClass($class);
		$args = [];
		foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			if ($parameter->getName() === 'userId') $args[] = $uid;
			elseif ($parameter->getName() === 'isCLI') $args[] = false;
			elseif ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
				$name = $type->getName();
				$args[] = in_array($name, ['OCA\\Tables\\Db\\TableMapper', 'OCA\\Tables\\Db\\ViewMapper', 'OCA\\Tables\\Db\\ColumnMapper', 'OCA\\Tables\\Db\\ShareMapper', 'OCA\\Tables\\Db\\ContextMapper'], true) ? $this->fresh($name, $uid) : $this->container->get($name);
			} elseif ($parameter->isDefaultValueAvailable()) $args[] = $parameter->getDefaultValue();
			else throw new TablesUnavailableException();
		}
		return $reflection->newInstanceArgs($args);
	}
}
