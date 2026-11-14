<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\Tools\SetTag;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Modules\Files\SetTagWorld;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * files_set_tag through the real endpoint over the verbatim native DAV pipeline of NC35 (the pin whose relation
 * collection the integration cells run newest): plan without effect, strict confirm, PlanChanged, the native
 * relation write and every refusal. Each write consumes one of alice's own files, so the refusals that follow are
 * asked against a file where the same call would still assign the tag.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FilesTaggingEffectsContractTest extends TestCase {
	/** Alice's own files, in the order the flow consumes them. */
	private const FILES = [SetTagWorld::OWN, ...SetTagWorld::MORE_OWN];

	private EffectsGate $gate;
	private SetTagWorld $world;

	/** @return void the native world over the gate's policy, served as the Files module */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->world = new SetTagWorld($this, '35', $this->gate->policy());
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $id === SetTag::class ? $this->world->tool
			: throw new \LogicException('Only files_set_tag is served here: ' . $id));
		$this->gate->serve(static fn (): array => [new FilesModule($container)]);
	}

	/** @return void Contract assigned to alice's next untagged file; the plan changes when someone tags it first */
	public function testAssigningATag(): void {
		$flow = (new CriticalFlow('set-tag', 'files_set_tag', 'files', 'tags',
			fn (): array => ['path' => '/f' . $this->target() . '.txt', 'tag' => 'Contract', 'assigned' => true],
			fn (): array => ['relations' => $this->world->relations, 'writes' => $this->world->writes]))
			// Another client assigns the same tag between the plan and the confirmation: nothing is left to write.
			->changing(function (): void { $this->world->relations[$this->target()][] = SetTagWorld::CONTRACT; })
			->hiding(function (): void { $this->world->hidden[] = $this->target(); }, function (): void { $this->world->hidden = [SetTagWorld::HIDDEN]; });
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		self::assertSame(['assign', 'assign'], array_column($this->world->writes, 0), 'the confirmed call and the guest confirmation wrote one relation each');
	}

	/** @return int the first of alice's own files that does not have the tag yet */
	private function target(): int {
		foreach (self::FILES as $id) {
			if (!in_array(SetTagWorld::CONTRACT, $this->world->relations[$id], true)) {
				return $id;
			}
		}
		self::fail('No untagged file left');
	}
}
