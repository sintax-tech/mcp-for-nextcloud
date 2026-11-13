<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Modules\Availability\AbsenceNativeRig;
use OCA\Mcp\Tests\Unit\Modules\Availability\AvailabilityDiscoveryProbe;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * availability_set_absence through the real endpoint over the verbatim DAV AbsenceService: plan without effect,
 * strict confirm, PlanChanged, the native write and every refusal. Bob's absence and jobs are part of the state, so
 * any step that touched them would fail the transcript.
 */
final class AvailabilityEffectsContractTest extends TestCase {
	private EffectsGate $gate;
	private AbsenceNativeRig $rig;

	/** @return void the rig over the gate's policy, with Bob's future absence already scheduled */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->rig = new AbsenceNativeRig($this, $this->gate->policy());
		$this->rig->seed('bob', '2027-03-01', '2027-03-05');
		$this->rig->settle();
		$logger = $this->createMock(LoggerInterface::class);
		$this->gate->serve(fn (): array => [$this->rig->module()], static fn (): CapabilityRegistry => new CapabilityRegistry([new AvailabilityDiscoveryProbe()], $logger));
	}

	/** @return array<string,mixed> stored absences, scheduled jobs, dispatched events and every native effect so far */
	private function state(): array {
		return ['absences' => $this->rig->table(), 'jobs' => $this->rig->jobs, 'effects' => $this->rig->effects,
			'events' => array_map(static fn (object $e): array => [$e::class, $e->getData()->getUser()->getUID(), $e->getData()->getId()], $this->rig->events)];
	}

	/** @return void a new absence of alice with a replacement; the plan changes when the replacement's display name does */
	public function testCreatingAnAbsence(): void {
		$flow = (new CriticalFlow('create', 'availability_set_absence', 'availability', 'absence',
			['first_day' => '2027-02-01', 'last_day' => '2027-02-03', 'status' => 'Vacation', 'message' => 'Ask Carol', 'replacement_user_id' => 'carol'],
			fn (): array => $this->state()))
			->changing(function (): void { $this->rig->names['carol'] = 'Carol Renamed'; })
			->withoutVisibility('An absence is the actor\'s own account setting, not a Files node; no other account can be addressed.');
		(new FlowRunner($this, $this->gate, 'availability'))->run($flow);
	}

	/** @return void alice's stored absence changed; the plan changes when another client changed it after the plan */
	public function testUpdatingAnAbsence(): void {
		$this->rig->seed('alice', '2027-01-20', '2027-01-22', 'carol');
		$this->rig->settle();
		$flow = (new CriticalFlow('update', 'availability_set_absence', 'availability', 'absence',
			['first_day' => '2027-02-01', 'last_day' => '2027-02-03', 'status' => 'Vacation', 'message' => 'Ask Carol'],
			fn (): array => $this->state()))
			->changing(function (): void {
				$this->rig->service->createOrUpdateAbsence($this->rig->user('alice'), '2027-01-20', '2027-01-23', 'Elsewhere', 'Changed by another client', 'carol', 'Carol');
			})
			->withoutVisibility('An absence is the actor\'s own account setting, not a Files node; no other account can be addressed.');
		(new FlowRunner($this, $this->gate, 'availability'))->run($flow);
	}
}
