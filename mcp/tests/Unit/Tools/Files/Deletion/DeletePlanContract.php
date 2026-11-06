<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Modules\Files\Deletion\DeleteEligibility;
use OCA\Mcp\Modules\Files\Deletion\DeleteTrashVeto;
use OCA\Mcp\Modules\Files\Handler\DeleteFileHandler;
use PHPUnit\Framework\TestCase;

/** Adds the new handler's actual plan to the pre-existing readability fixture without altering old write fixtures. */
final class DeletePlanContract {
	/**
	 * @param TestCase $test PHPUnit owner of the fixture.
	 * @param FakeTree $tree Existing synthetic native view.
	 * @param array<string, mixed> $plans Plans of the older tools.
	 * @return array<string, mixed> Includes the actual DeleteFileHandler plan for the known readable file.
	 */
	public static function include(TestCase $test, FakeTree $tree, array $plans): array {
		$mock = static fn ($class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
		$eligibility = $mock(DeleteEligibility::class);
		$eligibility->method('file')->willReturn($tree->node('/alice/files/Documentos/ata.md'));
		$handler = new DeleteFileHandler($eligibility, $mock(LockAwareWrite::class), new \Psr\Log\NullLogger(), new DeleteTrashVeto($mock(\OCP\EventDispatcher\IEventDispatcher::class)));
		$plans['files_delete'] = $handler->plan($tree->rootFolder(), 'alice', ['path' => '/Documentos/ata.md']);
		return $plans;
	}
}
