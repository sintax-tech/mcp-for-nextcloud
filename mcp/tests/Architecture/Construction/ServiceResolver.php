<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Construction;

use Closure;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

/** Constructs real app services and invokes every registered callable, with an observable construction stack. */
final class ServiceResolver implements ContainerInterface {
	/** @var array<string,object> Constructed instances. */
	private array $instances = [];
	/** @var list<string> Active construction stack. */
	private array $stack = [];
	/** @var array<string,true> Invoked factory names. */
	public array $invoked = [];
	/** @var array<string,list<string>> Immediate observed get() edges; deferred closures are not executed. */
	public array $edges = [];
	/** @var array<string,true> External type inventory. */
	public array $externalTypes = [];

	/** @param array<string,callable> $factories Registered service callables, including non-closures. @param Closure(string):object $external External typed doubles only. */
	public function __construct(public readonly array $factories, private Closure $external) {
	}

	/** @param string $id Requested service. @return bool Class or explicit binding exists. */
	public function has(string $id): bool { return isset($this->factories[$id]) || class_exists($id) || interface_exists($id); }

	/** @param string $id Service key. @return object Real internal object or typed external double. @throws LogicException Unbound, circular or wrong-typed service. */
	public function get(string $id): object {
		if ($id === ContainerInterface::class || $id === \OCP\IContainer::class) { return $this; }
		if ($this->stack !== []) { $this->edges[end($this->stack)][] = $id; }
		if (isset($this->instances[$id])) { return $this->instances[$id]; }
		if (in_array($id, $this->stack, true)) { throw new LogicException('Construction cycle: ' . implode(' -> ', [...$this->stack, $id])); }
		$this->stack[] = $id;
		try {
			if (isset($this->factories[$id])) {
				$this->invoked[$id] = true;
				$value = ($this->factories[$id])($this);
			} elseif (str_starts_with($id, 'OCA\\Mcp\\')) {
				$reflection = new ReflectionClass($id);
				if (!$reflection->isInstantiable()) { throw new LogicException('Missing internal binding: ' . $id); }
				$arguments = [];
				foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
					$type = $parameter->getType();
					if ($parameter->isVariadic()) { continue; }
					if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
						$name = $type->getName();
						if ($parameter->isDefaultValueAvailable() && !isset($this->factories[$name])) { $arguments[] = $parameter->getDefaultValue(); }
						else { $arguments[] = $this->get($name); }
					} elseif ($parameter->isDefaultValueAvailable()) { $arguments[] = $parameter->getDefaultValue(); }
					elseif ($parameter->getName() === 'appName' && $type instanceof ReflectionNamedType && $type->getName() === 'string') { $arguments[] = 'mcp'; }
					else { throw new LogicException('Unresolved primitive: ' . $id . '::$' . $parameter->getName()); }
				}
				$value = $reflection->newInstanceArgs($arguments);
			} else {
				$this->externalTypes[$id] = true;
				$value = ($this->external)($id);
			}
			if (!$value instanceof $id) { throw new LogicException('Wrong service type: ' . $id . ' returned ' . get_debug_type($value)); }
			return $this->instances[$id] = $value;
		} finally { array_pop($this->stack); }
	}

	/** @param list<string> $roots All module roots, including legacy modules. @return void Resolve all factories and roots. */
	public function resolveAll(array $roots): void {
		if ($this->factories === [] || $roots === []) { throw new LogicException('Empty DI coverage'); }
		foreach ([...array_keys($this->factories), ...$roots] as $id) { $this->get($id); }
		$expected = array_keys($this->factories); $actual = array_keys($this->invoked); sort($expected); sort($actual);
		if ($expected !== $actual) { throw new LogicException('Captured/invoked factory sets differ'); }
		ConstructionGraph::assertAcyclic($this->edges);
	}
}
