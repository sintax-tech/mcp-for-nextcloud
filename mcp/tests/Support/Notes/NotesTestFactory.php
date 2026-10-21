<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Notes;

use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\AppInfo\Application;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
/** Shared host-double setup; Notes module/tool factories are the production registrations. */
final class NotesTestFactory
{

    public static function module(TestCase $test, array $services): NotesModule
    {
        $factories = [];
        $registration = (new \ReflectionMethod($test, 'createMock'))->invoke($test, \OCP\AppFramework\Bootstrap\IRegistrationContext::class);
        $registration->method('registerService')->willReturnCallback(static function (string $id, callable $factory) use (&$factories): void {
            $factories[$id] = $factory;
        });
        (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($registration);
        $container = null;
        $resolve = function (string $id) use (&$resolve, &$services, &$container, $factories) {
            if (array_key_exists($id, $services)) {
                return $services[$id];
            }
            if (isset($factories[$id])) {
                return $services[$id] = $factories[$id]($container);
            }
            $r = new \ReflectionClass($id);
            $args = [];
            foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
                $t = $p->getType();
                $args[] = $t instanceof \ReflectionNamedType && !$t->isBuiltin() ? $resolve($t->getName()) : $p->getDefaultValue();
            }
            return $services[$id] = $r->newInstanceArgs($args);
        };
        $container = new class($resolve) implements ContainerInterface
        {
            public function __construct(private \Closure $resolve)
            {
            }
            public function get(string $id): mixed
            {
                return ($this->resolve)($id);
            }
            public function has(string $id): bool
            {
                return class_exists($id) || interface_exists($id);
            }
        };
        return $resolve(NotesModule::class);
    }
}
