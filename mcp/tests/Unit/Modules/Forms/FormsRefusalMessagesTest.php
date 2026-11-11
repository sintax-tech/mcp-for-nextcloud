<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Creation\CreateFormContext;
use OCA\Mcp\Modules\Forms\Creation\CreatePolicyBoundary;
use OCA\Mcp\Modules\Forms\Editing\EditPolicyBoundary;
use OCA\Mcp\Modules\Forms\Integration\EditNativeFactory;
use OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory;
use OCA\Mcp\Modules\Forms\Integration\FormsServices;
use OCA\Mcp\Modules\Forms\Presentation\CreateFormMessages;
use OCA\Mcp\Modules\Forms\Presentation\EditMessages;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Editing\TypeChanges;
use OCA\Mcp\Modules\Forms\Integration\StructureState;
use OCA\Mcp\Modules\Forms\Integration\StructureTarget;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Modules\Forms\Restructuring\StructureFlow;
use OCA\Mcp\Modules\Forms\Restructuring\StructurePlan;
use OCA\Mcp\Modules\Forms\Restructuring\StructureWriter;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

/** Discriminates exact-version refusals from creation-admission denials without entering native constructors. */
final class FormsRefusalMessagesTest extends TestCase {
	/** Resets the account translator after each isolated path.
	 * @return void
	 */
	protected function tearDown(): void {
		Translator::reset();
	}

	/** An unknown creation pin keeps its classified refusal through the factory catch.
	 * @return void
	 */
	public function testCreationVersionRefusalBeforeConstruction(): void {
		$factory = $this->creation('5.4.3');
		$this->expectExceptionMessage('Form creation is not available for the installed Forms version (5.4.3).');
		$factory->open('alice');
	}

	/** Session, Guests and effective grant denials share one user-facing message.
	 * @return void
	 */
	public function testUserAdmissionMessages(): void {
		foreach (['session', 'guests', 'grant'] as $mode) {
			try {
				$this->creation('5.4.2', $mode)->open('alice');
				self::fail('User denial reached native construction');
			} catch (ToolFailure $error) {
				self::assertSame('Form creation is not permitted for this user.', $error->getMessage(), $mode);
			}
		}
	}

	/** Native canCreateForms denial is indistinguishable from a grant denial at re-admission.
	 * @return void
	 */
	public function testNativeCreationPermissionDenial(): void {
		$service = new \stdClass();
		$config = new class {
			/** @return bool Native creation permission denied. */
			public function canCreateForms(): bool { return false; }
		};
		$context = new CreateFormContext('5.4.2', new \stdClass(), $config, $service, new \stdClass(), new \stdClass(), new \stdClass(), new \stdClass());
		$this->expectExceptionMessage('Form creation is not permitted for this user.');
		$this->creation('5.4.2', 'allowed', $service)->assertAllowed('alice', $context);
	}

	/** Existing classified ToolFailure instances are propagated rather than wrapped into the mixed refusal.
	 * @return void
	 */
	public function testClassifiedFailureIsPreserved(): void {
		$failure = new ToolFailure(CreateFormMessages::unsupportedVersion('5.5.0'));
		try {
			$this->creation('5.4.2', 'allowed', null, $failure)->open('alice');
			self::fail('Classified failure was ignored');
		} catch (ToolFailure $actual) {
			self::assertSame($failure, $actual);
		}
	}

	/** Point editing names editing at both initial admission and the renewed pre-write boundary.
	 * @return void
	 */
	public function testUnknownPointEditingVersionRefusal(): void {
		$factory = $this->editing();
		$context = new EditContext('5.4.1', ...array_fill(0, 6, new \stdClass()));
		foreach (['open', 'assertAllowed'] as $stage) {
			try {
				if ($stage === 'open') { $factory->open('alice'); }
				else { $factory->assertAllowed('alice', $context); }
				self::fail('Unknown version admitted point editing');
			} catch (ToolFailure $error) {
				self::assertSame('Editing forms is not available for the installed Forms version (5.5.0).', $error->getMessage(), $stage);
			}
		}
	}

	/** Real structure orchestration selects its own version message in preview and pre-call re-admission.
	 * @return void
	 */
	public function testUnknownStructureVersionRefusal(): void {
		require_once __DIR__ . '/StructureHarness.php';
		$f = new StructureFixture(); $factory = $this->editing();
		$states = new StructureState(new StructureTarget());
		$plans = new StructurePlan(new PlanState((new InMemoryConfig())->mock($this)));
		$throttler = $this->createMock(\OCP\Security\Bruteforce\IThrottler::class);
		$throttler->expects(self::never())->method('registerAttempt');
		$writer = new StructureWriter($factory, $states, $plans,
			new CreateNativeThrottle($throttler, $this->createMock(\OCP\IRequest::class)));
		$flow = new StructureFlow($factory, $states, $plans, $writer);
		$op = new TypeChanges(); $args = StructureHarness::arguments()[$op->name()];
		$c = $f->context(); $r = $op->request($args); $s = $states->read($c, 1, 'alice'); $e = $op->effect($c, $r, $s);
		$plan = $plans->of($op, $r, $c, $s, $e, 'alice');
		foreach (['preview', 'write'] as $stage) {
			try {
				if ($stage === 'preview') { $flow->preview($op, $args, 'alice'); }
				else { $writer->write($op, $c, $r, $s, $e, $plan, 'alice'); }
				self::fail('Unknown version admitted structure changes');
			} catch (ToolFailure $error) {
				self::assertSame('Structure changes are not available for the installed Forms version (5.5.0).', $error->getMessage(), $stage);
			}
		}
		self::assertSame([], $f->calls);
	}

	/** The version and permission messages interpolate the exact installed version in every shipped language.
	 * @return void
	 */
	public function testMessagesInAllFourLanguages(): void {
		$expected = [
			'en' => ['Form creation is not available for the installed Forms version (5.4.2).', 'Form creation is not permitted for this user.',
				'Forms 5.4.2 does not allow changing a question type. No change was attempted.', 'Structure changes are not available for the installed Forms version (5.4.2).', 'Editing forms is not available for the installed Forms version (5.4.2).'],
			'pt_BR' => ['A criação de formulários não está disponível para a versão instalada do Forms (5.4.2).', 'A criação de formulários não é permitida para este usuário.',
				'O Forms 5.4.2 não permite trocar o tipo de uma pergunta. Nenhuma alteração foi tentada.', 'Alterações de estrutura não estão disponíveis para a versão instalada do Forms (5.4.2).', 'A edição de formulários não está disponível para a versão instalada do Forms (5.4.2).'],
			'es' => ['La creación de formularios no está disponible para la versión instalada de Forms (5.4.2).', 'La creación de formularios no está permitida para este usuario.',
				'Forms 5.4.2 no permite cambiar el tipo de una pregunta. No se intentó ningún cambio.', 'Los cambios de estructura no están disponibles para la versión instalada de Forms (5.4.2).', 'La edición de formularios no está disponible para la versión instalada de Forms (5.4.2).'],
			'de' => ['Die Formularerstellung ist für die installierte Forms-Version (5.4.2) nicht verfügbar.', 'Die Formularerstellung ist für diesen Benutzer nicht erlaubt.',
				'Forms 5.4.2 erlaubt keine Änderung des Fragetyps. Es wurde keine Änderung versucht.', 'Strukturänderungen sind für die installierte Forms-Version (5.4.2) nicht verfügbar.', 'Die Bearbeitung von Formularen ist für die installierte Forms-Version (5.4.2) nicht verfügbar.'],
		];
		foreach ($expected as $language => $messages) {
			Translator::use(new JsonL10n($language));
			self::assertSame($messages, [CreateFormMessages::unsupportedVersion('5.4.2'), CreateFormMessages::userDenied(),
				StructureMessages::typeChangeUnavailable('5.4.2'), StructureMessages::unsupportedVersion('5.4.2'), EditMessages::unsupportedVersion('5.4.2')], $language);
		}
	}

	/** Builds a real editing admission boundary that must refuse before native construction.
	 * @return EditNativeFactory Factory probing an unknown installed version.
	 */
	private function editing(): EditNativeFactory {
		$forms = $this->createMock(FormsServices::class);
		$forms->method('open')->willReturn(['version' => '5.5.0']);
		$guests = $this->createMock(GuestRestrictions::class); $guests->method('allowsApp')->willReturn(true);
		$factory = $this->getMockBuilder(EditNativeFactory::class)->setConstructorArgs([
			$this->session(), $forms, $this->createMock(EditPolicyBoundary::class), $guests,
		])->onlyMethods(['container'])->getMock();
		$factory->expects(self::never())->method('container');
		return $factory;
	}

	/** Builds only the pre-constructor creation boundary with controllable admission outcomes.
	 * @param string $version Installed version.
	 * @param string $mode Admission gate to refuse, or allowed.
	 * @param object|null $service Proven read service identity.
	 * @param ToolFailure|null $failure Classified read-boundary error.
	 * @return FormsCreateNativeFactory Factory whose native container must remain unopened.
	 */
	private function creation(string $version, string $mode = 'allowed', ?object $service = null, ?ToolFailure $failure = null): FormsCreateNativeFactory {
		$forms = $this->createMock(FormsServices::class);
		if ($failure !== null) { $forms->method('open')->willThrowException($failure); }
		else { $forms->method('open')->willReturn(['version' => $version, 'service' => $service ?? new \stdClass()]); }
		$policy = $this->createMock(CreatePolicyBoundary::class);
		if ($mode === 'grant') { $policy->method('assertAllowed')->willThrowException(new ToolFailure(CreateFormMessages::userDenied())); }
		$guests = $this->createMock(GuestRestrictions::class); $guests->method('allowsApp')->willReturn($mode !== 'guests');
		$factory = $this->getMockBuilder(FormsCreateNativeFactory::class)->setConstructorArgs([
			$this->session($mode === 'session' ? 'bob' : 'alice'), $forms, $policy, $guests,
		])->onlyMethods(['container'])->getMock();
		$factory->expects(self::never())->method('container');
		return $factory;
	}

	/** Creates a real-session-shaped account boundary without impersonation or native service construction.
	 * @param string $uid Session account ID.
	 * @return \OCP\IUserSession Mock authenticated session.
	 */
	private function session(string $uid = 'alice'): \OCP\IUserSession {
		$user = $this->createMock(\OCP\IUser::class); $user->method('getUID')->willReturn($uid);
		$session = $this->createMock(\OCP\IUserSession::class); $session->method('getUser')->willReturn($user);
		return $session;
	}
}
