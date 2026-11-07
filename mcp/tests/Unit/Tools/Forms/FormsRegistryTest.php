<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Modules\Forms\Integration\FormsServices;
use OCA\Mcp\Modules\Forms\Integration\FormsIdentityProbe;
use OCA\Mcp\Modules\Forms\Reading\ListFormsHandler;
use OCA\Mcp\Modules\Forms\Reading\ReadFormHandler;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FormsFixture.php';

/** Registry/catalog/availability boundary with separate denied creation and results grants. */
final class FormsRegistryTest extends TestCase {
	/** Delegates closed read/create schema and independent default-deny checks.
	 * @return void
	 */
	public function testReadToolsAndSeparateDeniedCreationAndResults(): void {
		\OCA\Mcp\Tests\Unit\Tools\Forms\Creation\CreateCatalogContract::check($this);
	}

	/** Refuses disabled and unsupported app versions before resolving any native service or mapper.
	 * @return void
	 */
	public function testUnavailableNeverResolvesNativeServices(): void {
		foreach ([[false,'5.4.1'],[true,'5.2.10']] as [$enabled,$version]) {
			$user=$this->createMock(\OCP\IUser::class); $users=$this->createMock(\OCP\IUserManager::class); $users->method('get')->willReturn($user);
			$apps=$this->createMock(\OCP\App\IAppManager::class);$apps->method('isEnabledForUser')->willReturn($enabled);$apps->method('getAppVersion')->willReturn($version);
			$container=$this->createMock(\Psr\Container\ContainerInterface::class);$container->expects(self::never())->method('get');
			try { (new FormsServices($apps,$users,$container,new FormsIdentityProbe($this->createMock(\OCP\IUserSession::class))))->open('alice');self::fail('Unavailable app admitted'); }
			catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure) { self::assertTrue(true); }
		}
	}
}
