<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Ten endpoint effect transcripts exercise the actual tools, gate, plans and handlers. */
final class PollsMutationEffectsTest extends TestCase {
	/**
	 * @return iterable<string,array{string,array<string,mixed>}> Every new write.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function tools(): iterable {
		return PollsMutationCases::all();
	}

	/**
	 * @param string $tool Exact public name.
	 * @param array<string,mixed> $arguments Valid request.
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	#[DataProvider('tools')]
	public function testEachWriteHasAnEndpointEffectTranscript(string $tool, array $arguments): void {
		$scene = new PollsMutationScene($tool);
		$gate = new EffectsGate($this);
		$gate->serve(
			fn(): array => [PollsMutationModule::build($this, $scene)],
			fn(): CapabilityRegistry => new CapabilityRegistry([PollsDiscoveryProbe::build($this)], $this->createMock(LoggerInterface::class))
		);
		$flow = (new CriticalFlow(
			substr($tool, 6),
			$tool,
			'polls',
			PollsWriteSchema::OPERATIONS[$tool],
			$arguments,
			fn(): array => ['state' => $scene->state, 'writes' => $scene->writes]
		))->changing(
			function () use ($scene): void {
				$scene->state['poll']['title'] = 'Changed by another actor';
			}
		)->hiding(
			function () use ($scene): void {
				$scene->deny = true;
			},
			function () use ($scene): void {
				$scene->deny = false;
			}
		);
		(new FlowRunner($this, $gate, 'polls'))->run($flow);
	}
}
