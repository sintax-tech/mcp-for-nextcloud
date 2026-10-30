<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

require_once __DIR__ . '/../../Tools/Forms/NativeFormsFixture.php';

use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * Admission contract of the real Forms native factories (RE-01), frozen to `effects/forms.native-admission.json`.
 *
 * Every Forms effect contract replaces the factories with a double that hands out a fixture graph, because their
 * open() builds Forms' own ConfigService, SubmissionService and ApiController, classes that only exist in a Nextcloud
 * with Forms installed. What CAN run here without any production change is everything before that construction and
 * the per-stage recheck after it:
 * - open(): session, Guests, the renewed policy (real boundary over the real resolver), the Forms read probe and its
 *   version, in the production order. An admitted open() reaches the native construction and stops on the first missing
 *   Forms class, which is how the contract tells "admitted" from "refused" (the native construction was reached or not);
 * - assertAllowed(): the same gates again on the graph open() returned, plus its service identity, its version and, for
 *   creation, the native canCreateForms answer.
 */
final class FormsNativeAdmissionContractTest extends TestCase {
	/** Golden transcript of the admission cases. */
	private const GOLDEN = __DIR__ . '/../effects/forms.native-admission.json';

	/** The gate whose real policy the boundaries renew. */
	private EffectsGate $gate;

	/**
	 * @return void builds the gate whose real policy the boundaries renew: the MCP service on, alice eligible, connected
	 *     and holding every grant
	 */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->gate->policy()->setGlobalEnabled(true);
		$this->gate->policy()->setEligible(EffectsGate::USER, true);
		$this->gate->policy()->setConnected(EffectsGate::USER, true);
	}

	/** @return void every admission case of both factories, frozen, with the admitted and refused cases asserted directly */
	public function testTheNativeFactoriesAdmitOnlyTheCurrentActor(): void {
		$transcript = [];
		foreach (['create' => ['forms', 'create'], 'edit' => ['forms', 'edit']] as $factory => [$module, $operation]) {
			foreach ($this->cases($module, $operation, $factory === 'create') as $case => $change) {
				$transcript[$factory]['open'][$case] = $this->open($factory, $case, $change);
				$transcript[$factory]['assertAllowed'][$case] = $this->recheck($factory, $case, $change);
			}
		}
		GoldenFile::assertMatches($this, self::GOLDEN, $transcript);
	}

	/**
	 * The world changes of each case, the first one changing nothing.
	 *
	 * @param string $module grant module of the factory
	 * @param string $operation grant operation of the factory
	 * @param bool $creation whether the factory creates (only creation asks canCreateForms)
	 * @return array<string, \Closure(FormsAdmissionWorld): void> case name => what it changes
	 */
	private function cases(string $module, string $operation, bool $creation): array {
		$cases = [
			'admitted' => static function (FormsAdmissionWorld $world): void {
			},
			'no-session' => static function (FormsAdmissionWorld $world): void {
				$world->session = null;
			},
			'other-account-in-session' => static function (FormsAdmissionWorld $world): void {
				$world->session = 'bob';
			},
			'guest-without-forms' => static function (FormsAdmissionWorld $world): void {
				$world->guestsAllowForms = false;
			},
			'policy-renewed-without-grant' => function (FormsAdmissionWorld $world) use ($module, $operation): void {
				$this->gate->grant($module, $operation, false);
			},
			'forms-unavailable' => static function (FormsAdmissionWorld $world): void {
				$world->formsAvailable = false;
			},
			'unsupported-forms-version' => static function (FormsAdmissionWorld $world): void {
				$world->version = '5.2.0';
			},
			'other-forms-service' => static function (FormsAdmissionWorld $world): void {
				$world->otherService = true;
			},
		];
		if ($creation) {
			$cases['can-create-forms-false'] = static function (FormsAdmissionWorld $world): void {
				$world->canCreateForms = false;
			};
		}
		return $cases;
	}

	/**
	 * open() of the real factory in a world changed by one case.
	 *
	 * @param string $factory create or edit
	 * @param string $case case name
	 * @param \Closure(FormsAdmissionWorld): void $change the change of the case
	 * @return array{outcome:string, message:string, cause:string} refused before the native construction, or admitted
	 *     (the native construction was reached and stopped on the missing Forms class)
	 */
	private function open(string $factory, string $case, \Closure $change): array {
		$world = $this->world($change);
		try {
			$factory === 'create' ? $world->createFactory()->open(EffectsGate::USER) : $world->editFactory()->open(EffectsGate::USER);
			$this->fail($factory . ' open(): ' . $case . ' cannot complete without the Forms classes');
		} catch (ToolFailure $refusal) {
			$reached = $world->containerOpened > 0;
			$cause = $refusal->getPrevious();
			// Admitted: past every gate, into the native construction, stopped by the first Forms class that is absent here.
			$admitted = $reached && $cause instanceof \Error && str_contains($cause->getMessage(), 'OCA\\Forms\\Service\\ConfigService');
			// Cases that only a later stage can see (the identity of the graph, canCreateForms) do pass open().
			$this->assertSame(in_array($case, ['admitted', 'other-forms-service', 'can-create-forms-false'], true), $admitted,
				$factory . ' open(): ' . $case . ' ' . ($cause === null ? '' : get_class($cause) . ': ' . $cause->getMessage()));
			return ['outcome' => $admitted ? 'admitted: reached the native construction' : 'refused before the native construction',
				'message' => $refusal->getMessage(), 'cause' => $cause === null ? '' : get_class($cause) . ': ' . $cause->getMessage()];
		} finally {
			$this->gate->grant('forms', 'create', true);
			$this->gate->grant('forms', 'edit', true);
		}
	}

	/**
	 * assertAllowed() of the real factory on the graph open() would have built, in a world changed by one case.
	 *
	 * @param string $factory create or edit
	 * @param string $case case name
	 * @param \Closure(FormsAdmissionWorld): void $change the change of the case
	 * @return array{outcome:string, message:string} admitted, or refused with the message the tool would answer
	 */
	private function recheck(string $factory, string $case, \Closure $change): array {
		$world = $this->world($change);
		try {
			$factory === 'create' ? $world->createFactory()->assertAllowed(EffectsGate::USER, $world->createContext())
				: $world->editFactory()->assertAllowed(EffectsGate::USER, $world->editContext());
			$this->assertSame('admitted', $case, $factory . ' assertAllowed(): ' . $case . ' must be refused');
			return ['outcome' => 'admitted', 'message' => ''];
		} catch (ToolFailure $refusal) {
			$this->assertNotSame('admitted', $case, $factory . ' assertAllowed(): the current actor must be admitted: ' . $refusal->getMessage());
			return ['outcome' => 'refused', 'message' => $refusal->getMessage()];
		} finally {
			$this->assertSame(0, $world->containerOpened, 'a recheck never builds a native graph');
			$this->gate->grant('forms', 'create', true);
			$this->gate->grant('forms', 'edit', true);
		}
	}

	/**
	 * @param \Closure(FormsAdmissionWorld): void $change the change of a case
	 * @return FormsAdmissionWorld a world where alice is admitted, changed by the case
	 */
	private function world(\Closure $change): FormsAdmissionWorld {
		$world = new FormsAdmissionWorld($this, $this->gate);
		$change($world);
		return $world;
	}
}
