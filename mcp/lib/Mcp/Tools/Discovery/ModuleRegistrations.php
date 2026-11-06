<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools\Discovery;

use LogicException;
use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the {@see ModuleRegistration} of every {@see CatalogModule} of Application::MODULES, in that order, and
 * registers the {@see CapabilityRegistry} with their probes. Catalogs without a registration contribute no bindings.
 */
final class ModuleRegistrations {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param IRegistrationContext $context registration context of the app
	 * @param list<class-string> $modules Application::MODULES
	 * @throws LogicException when a module is not a CatalogModule or names a class that is not a ModuleRegistration
	 */
	public static function register(IRegistrationContext $context, array $modules): void {
		$probes = [];
		foreach (self::of($modules) as $registration) {
			$registration->register($context);
			$probes = [...$probes, ...$registration->probes()];
		}
		$context->registerService(CapabilityRegistry::class, static fn (ContainerInterface $c): CapabilityRegistry => new CapabilityRegistry(
			self::resolve($c, $probes),
			$c->get(LoggerInterface::class),
		));
	}

	/**
	 * The registrations the modules name, without building any module.
	 *
	 * @param list<class-string> $modules module classes, in order
	 * @return list<ModuleRegistration> registrations, in module order
	 * @throws LogicException when a module is not a CatalogModule or names a class that is not a ModuleRegistration
	 */
	public static function of(array $modules): array {
		$registrations = [];
		foreach ($modules as $module) {
			if (!is_subclass_of($module, CatalogModule::class)) {
				throw new LogicException("$module does not extend " . CatalogModule::class);
			}
			$class = $module::registration();
			if ($class === null) {
				continue;
			}
			if (!is_subclass_of($class, ModuleRegistration::class)) {
				throw new LogicException("$module: $class does not implement " . ModuleRegistration::class);
			}
			$registrations[] = new $class();
		}
		return $registrations;
	}

	/**
	 * One factory per probe class, so the registry builds each probe only when first asked and isolates a probe whose
	 * resolution fails (container, constructor, autoload) from the others.
	 *
	 * @param ContainerInterface $c app container
	 * @param list<class-string<CapabilityProbe>> $probes probe classes
	 * @return array<class-string<CapabilityProbe>, \Closure(): CapabilityProbe> factories by probe class
	 */
	private static function resolve(ContainerInterface $c, array $probes): array {
		$factories = [];
		foreach ($probes as $probe) {
			$factories[$probe] ??= static fn (): mixed => $c->get($probe);
		}
		return $factories;
	}
}
