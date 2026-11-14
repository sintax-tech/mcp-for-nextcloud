<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Activity;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\References\ReadableCalendarEvent;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup;
use OCA\Mcp\Modules\Activity\Reading\ActivityCalendarReferences;
use OCA\Mcp\Modules\Activity\Reading\ActivityFileReferences;
use OCA\Mcp\Modules\Activity\Reading\ActivityObjectProjection;
use OCA\Mcp\Modules\Activity\Reading\ActivityObjectShapes;
use OCA\Mcp\Modules\Activity\Reading\ActivityOriginAccess;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Denied optional source projections for the unchanged metadata-only ACT-1 controls. */
final class ActivityProjectionFixture {
	/**
	 * @param TestCase $test owns all mocks
	 * @return ActivityObjectProjection real projector with source reads denied
	 */
	public static function denied(TestCase $test): ActivityObjectProjection {
		$mock=static fn(string $class)=>$test->getMockBuilder($class)->disableOriginalConstructor()->getMock();
		$policy=$mock(GrantPolicy::class); $policy->method('granted')->willReturn(false);
		$root=$mock(IRootFolder::class); $root->expects($test::never())->method('getUserFolder');
		$session=$mock(IUserSession::class); $urls=$mock(IURLGenerator::class);
		$access=new ActivityOriginAccess($policy,$mock(GuestRestrictions::class),$mock(IAppManager::class),$session);
		return new ActivityObjectProjection(new ActivityObjectShapes(),new ActivityFileReferences($access,new ActorRoot($root,$session),$mock(FilesAccessGuard::class),$urls),
			new ActivityCalendarReferences($access,$mock(ScopedCalendarLookup::class),$mock(ReadableCalendarEvent::class),$urls));
	}
}
