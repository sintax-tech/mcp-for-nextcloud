<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Forms\Creation;
use OCA\Mcp\Tools\Forms\Creation\{CreateFormContext,CreatePolicyBoundary,FormsCreateNativeFactory};
use OCA\Mcp\Tools\Forms\FormsServices;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

/** Separates stage admission gates from the native-constructor runtime proofs. */
final class CreateAdmissionTest extends TestCase {
	/** Refuses every changed gate without using a replacement graph or treating a grant as effective admission.
	 * @return void
	 */
	public function testStageAdmissionDenialsAndStableControl(): void {
		foreach (['stable', 'session', 'grant', 'guests', 'service', 'version', 'native', 'app-error'] as $mode) {
			$user = $this->createMock(\OCP\IUser::class); $user->method('getUID')->willReturn($mode === 'session' ? 'bob' : 'alice');
			$session = $this->createMock(\OCP\IUserSession::class); $session->method('getUser')->willReturn($user);
			$policy = $this->createMock(CreatePolicyBoundary::class);
			if ($mode === 'grant') { $policy->method('assertAllowed')->willThrowException(new ToolFailure('denied')); }
			$guests = $this->createMock(GuestRestrictions::class); $guests->method('allowsApp')->willReturn($mode !== 'guests');
			$service = new \stdClass(); $forms = $this->createMock(FormsServices::class);
			if ($mode === 'app-error') { $forms->method('open')->willThrowException(new ToolFailure('unavailable')); }
			else { $forms->method('open')->willReturn(['service' => $mode === 'service' ? new \stdClass() : $service, 'version' => $mode === 'version' ? '5.4.0' : '5.4.1']); }
			$config = new class($mode !== 'native') {
				/** Captures a single explicit native-admission fixture value.
				 * @param bool $allowed Native gate outcome.
				 */
				public function __construct(private bool $allowed) { }
				/** Reports the native fixture gate.
				 * @return bool Native gate outcome.
				 */
				public function canCreateForms(): bool { return $this->allowed; }
			};
			$context = new CreateFormContext('5.4.1', new \stdClass(), $config, $service, new \stdClass(), new \stdClass(), new \stdClass(), new \stdClass());
			try {
				(new FormsCreateNativeFactory($session, $forms, $policy, $guests))->assertAllowed('alice', $context);
				self::assertSame('stable', $mode);
			} catch (ToolFailure) { self::assertNotSame('stable', $mode); }
		}
	}
}
