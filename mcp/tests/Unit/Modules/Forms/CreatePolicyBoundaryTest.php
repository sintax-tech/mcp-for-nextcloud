<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Access\Policy\PolicyResolver;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Creation\CreatePolicyBoundary;
use OCA\Mcp\Modules\Forms\Presentation\CreateFormMessages;
use PHPUnit\Framework\TestCase;

/** Keeps classified admission failures intact and technical resolver faults out of user-permission messages. */
final class CreatePolicyBoundaryTest extends TestCase {
	/** A classified denial preserves its identity, message and existing cause without a redundant wrapper.
	 * @return void
	 */
	public function testClassifiedToolFailureIsRethrown(): void {
		$failure = new ToolFailure(CreateFormMessages::userDenied());
		$resolver = $this->createMock(PolicyResolver::class);
		$resolver->expects(self::once())->method('begin')->with('alice', [], [], false)->willThrowException($failure);
		try {
			(new CreatePolicyBoundary($resolver))->assertAllowed('alice');
			self::fail('The classified refusal was ignored');
		} catch (ToolFailure $actual) {
			self::assertSame($failure, $actual);
			self::assertSame('Form creation is not permitted for this user.', $actual->getMessage());
			self::assertNull($actual->getPrevious());
		}
	}

	/** A technical exception produces a safe generic refusal; diagnostic details stay in the cause only.
	 * @return void
	 */
	public function testTechnicalFailureUsesGenericRefusal(): void {
		$failure = new \RuntimeException('Private resolver diagnostic');
		$resolver = $this->createMock(PolicyResolver::class);
		$resolver->expects(self::once())->method('begin')->willThrowException($failure);
		try {
			(new CreatePolicyBoundary($resolver))->assertAllowed('alice');
			self::fail('The broken resolver admitted creation');
		} catch (ToolFailure $actual) {
			self::assertSame(CreateFormMessages::refused(), $actual->getMessage());
			self::assertNotSame(CreateFormMessages::userDenied(), $actual->getMessage());
			self::assertStringNotContainsString('Private resolver diagnostic', $actual->getMessage());
			self::assertSame($failure, $actual->getPrevious());
		}
	}
}
