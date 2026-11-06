<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\SchemaSharePolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\Share\IManager;
use OCP\{IUserManager, IGroupManager, IUser};
use PHPUnit\Framework\TestCase;

/** Direct native sharing restrictions, including excluded-group exceptions. */
final class SchemaSharePolicyTest extends TestCase {
	/** @return void All four policy branches and the explicit native exception are exercised. */
	public function testNativePolicyBranchesAndExclusions(): void {
		foreach (['disabled', 'group-disabled', 'user-outside', 'group-outside', 'same-group', 'excluded', 'missing'] as $case) {
			$manager = $this->createMock(IManager::class);
			$manager->method('sharingDisabledForUser')->willReturn($case === 'disabled');
			$manager->method('allowGroupSharing')->willReturn($case !== 'group-disabled');
			$manager->method('shareWithGroupMembersOnly')->willReturn(true);
			$manager->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn($case === 'excluded' ? ['team'] : []);
			$users = $this->createMock(IUserManager::class);
			$users->method('userExists')->willReturn($case !== 'missing');
			$alice = $this->createMock(IUser::class);
			$bob = $this->createMock(IUser::class);
			$users->method('get')->willReturnCallback(static fn (string $id): ?IUser => $id === 'alice' ? $alice : $bob);
			$groups = $this->createMock(IGroupManager::class);
			$groups->method('groupExists')->willReturn(true);
			$groups->method('getUserGroupIds')->willReturnCallback(static fn (IUser $user): array => $user === $alice || $case === 'same-group' ? ['team'] : ['other']);
			$policy = new SchemaSharePolicy($manager, $users, $groups);
			$recipient = ['type' => str_starts_with($case, 'group-') ? 'group' : 'user', 'id' => 'bob'];
			try {
				$policy->check($recipient, 'alice');
				$this->assertContains($case, ['same-group', 'excluded']);
			} catch (ToolFailure $error) {
				$this->assertNotContains($case, ['same-group', 'excluded']);
				$this->assertContains($error->getMessage(), [CommonMessages::forbidden(), CommonMessages::notFound()]);
			}
		}
	}
}
