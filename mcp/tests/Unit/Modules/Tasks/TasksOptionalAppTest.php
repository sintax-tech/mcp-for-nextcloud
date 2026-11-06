<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tasks;

use OCA\Mcp\Access\Grants\GrantCatalog;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Tasks\TasksModule;
use OCA\Mcp\Tests\Unit\Contract\Oracle\ModuleBuilder;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TasksOptionalAppTest extends TestCase {
	public function testTheToolsRemainAvailableWhenDavIsEnabledAndTheTasksUiAppIsAbsent(): void {
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		foreach (GrantCatalog::CATALOG['tasks'] as $operation) { $policy->setGrant('alice', 'tasks', $operation, true); }
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'dav');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$registry = new ToolRegistry([(new ModuleBuilder($this))->build(TasksModule::class)], $policy, $apps, $users, $this->createMock(LoggerInterface::class));
		self::assertSame(['tasks_list_calendars', 'tasks_list_tasks', 'tasks_read_task', 'tasks_create_task', 'tasks_edit_task', 'tasks_complete_task', 'tasks_delete_task'], array_column($registry->definitions('alice'), 'name'));
	}
	public function testExplicitNullStatusIsRejectedByTheRegistryBeforeDispatch(): void {
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		foreach (GrantCatalog::CATALOG['tasks'] as $operation) { $policy->setGrant('alice', 'tasks', $operation, true); }
		$apps = $this->createMock(IAppManager::class); $apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class); $users->method('get')->willReturn($this->createMock(IUser::class));
		$registry = new ToolRegistry([(new ModuleBuilder($this))->build(TasksModule::class)], $policy, $apps, $users, $this->createMock(LoggerInterface::class));
		foreach (['tasks_create_task', 'tasks_edit_task'] as $tool) {
			try {
				$args = ['calendar' => '/remote.php/dav/calendars/alice/tasks/', 'summary' => 'Work', 'status' => null];
				if ($tool === 'tasks_edit_task') { $args['uid'] = 't1'; }
				$registry->call($tool, $args, 'alice');
				self::fail('Explicit null status accepted');
			} catch (\InvalidArgumentException $error) {
				self::assertStringContainsString('status', $error->getMessage());
			}
		}
	}

}
