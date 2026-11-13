<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\Tools\AddComment;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Modules\Files\CommentWorld;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * files_add_comment through the real endpoint over the verbatim native comments pipeline of NC35 (the pin the
 * integration cells run newest): plan without effect, strict confirm, PlanChanged, the native POST with the author's
 * read marker and the mention notification, and every refusal.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FilesCommentingEffectsContractTest extends TestCase {
	private EffectsGate $gate;
	private CommentWorld $world;

	/** @return void the native world over the gate's policy, served as the Files module */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->world = new CommentWorld($this, '35', $this->gate->policy());
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $id === AddComment::class ? $this->world->tool
			: throw new \LogicException('Only files_add_comment is served here: ' . $id));
		$this->gate->serve(static fn (): array => [new FilesModule($container)]);
	}

	/** @return void a comment mentioning bob on alice's file; the plan changes when the file becomes a share */
	public function testAddingAComment(): void {
		$flow = (new CriticalFlow('add-comment', 'files_add_comment', 'files', 'comments',
			['path' => '/f42.txt', 'text' => 'Approved, @bob'],
			fn (): array => ['saves' => $this->world->saves, 'markers' => $this->world->markerWrites, 'notified' => $this->world->notified]))
			// The file becomes bob's share between the plan and the confirmation: the audience changed, nothing is written.
			->changing(function (): void { $this->world->shared[] = CommentWorld::OWN; })
			// From then on the file is shared, so every confirmation also confirms the shared write, as the user would.
			->confirmingWith(static fn (mixed $plan, array $arguments): array => $arguments + ['confirm' => true, 'confirm_shared' => true,
				'plan_state' => (string)($plan->result->structuredContent->plan_state ?? '')])
			// The native POST moves the author's marker to the real clock (EntityCollection::setReadMarker(null)).
			->normalizing(fn (): array => isset($this->world->markers[CommentWorld::OWN]) && $this->world->markerWrites !== []
				? [(new \DateTime($this->world->markers[CommentWorld::OWN]))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') => '<read-marker-now>']
				: [])
			->hiding(function (): void { $this->world->hidden[] = CommentWorld::OWN; }, function (): void { $this->world->hidden = [CommentWorld::HIDDEN]; });
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		self::assertSame('Approved, @bob', $this->world->saves[0][2] ?? null);
		self::assertSame(array_fill(0, count($this->world->saves), 'bob'), $this->world->notified, 'each saved comment notified bob once');
	}
}
