<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\ScopeController;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Tests\Unit\Service\Scope\ScopeFixture;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Effect contract of `scope#update` (RE-01): the resource scope of one user and module, through the real
 * {@see ScopeController} and policy writer over the SQLite scope tables of {@see ScopeFixture}. The effect is the
 * stored mode and items of alice's calendar scope, the revision and the policy events; every refusal leaves them as
 * they were.
 */
final class ScopeEffectsContractTest extends TestCase {
	private ScopeFixture $fx;
	/** @var array<string, mixed> parameters of the next request */
	private array $body = [];

	/** @return void the scope fixture: alice with two calendars, the scope tables on SQLite */
	protected function setUp(): void {
		$this->fx = new ScopeFixture($this);
	}

	/** @return void a closed barrier, the refusals of an open one, a save and the conflict of an outdated page */
	public function testScopeOfACalendar(): void {
		$transcript = new ControllerTranscript($this, 'scope.update', fn (): array => $this->state());
		$restricted = ['mode' => 'restricted', 'items' => ['12', '11', '12']];
		$this->refuse($transcript, 'barrier closed: 422', $restricted + ['expectedRevision' => $this->fx->revision()], false);
		$this->refuse($transcript, 'without expectedRevision: 428', $restricted, true);
		$this->refuse($transcript, 'an unknown mode: 400', ['mode' => 'everything', 'expectedRevision' => $this->fx->revision()], true);
		$this->refuse($transcript, 'more than 200 resources: 422', ['mode' => 'restricted', 'items' => array_map('strval', range(1, 201)),
			'expectedRevision' => $this->fx->revision()], true);
		$this->refuse($transcript, 'writer unusable: 503', $restricted + ['expectedRevision' => $this->fx->revision()], true, true);
		$stale = $this->fx->revision();
		$saved = $this->send($transcript, 'restricted to two calendars', $restricted + ['expectedRevision' => $stale], true);
		$this->assertSame(200, $saved['status']);
		$this->assertSame(['mode' => 'restricted', 'count' => 2], $saved['json']['scope']);
		// The receipt is the policy command (128 random bits) and its sequence, like every policy event id.
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}-[1-9][0-9]*$/', (string)$saved['json']['receiptId']);
		$this->refuse($transcript, 'outdated page: 409 with the saved scope', ['mode' => 'unrestricted', 'expectedRevision' => $stale], true);
		foreach ($this->fx->eventRows() as $row) {
			$transcript->literal(explode('-', $row['id'])[0], '<command#' . $row['id'][-1] . '>');
		}
		$transcript->assertGolden();
	}

	/**
	 * @param ControllerTranscript $transcript the transcript of the flow
	 * @param string $step what the step proves
	 * @param array<string, mixed> $body request parameters
	 * @param bool $open whether the scope barrier is open for the module
	 * @param bool $unusable whether the policy writer is unusable during the request
	 * @return void
	 */
	private function refuse(ControllerTranscript $transcript, string $step, array $body, bool $open, bool $unusable = false): void {
		$before = $this->state();
		$answer = $this->send($transcript, $step, $body, $open, $unusable);
		$this->assertNotSame(200, $answer['status'], $step);
		$this->assertEquals($before, $this->state(), $step . ': a refusal wrote something');
	}

	/**
	 * @param ControllerTranscript $transcript the transcript of the flow
	 * @param string $step what the step proves
	 * @param array<string, mixed> $body request parameters
	 * @param bool $open whether the scope barrier is open for the module
	 * @param bool $unusable whether the policy writer is unusable during the request
	 * @return array<string, mixed> the recorded answer
	 */
	private function send(ControllerTranscript $transcript, string $step, array $body, bool $open, bool $unusable = false): array {
		$this->body = $body;
		$writer = $this->fx->writer($open);
		$controller = $this->controller($writer, $open);
		$call = static fn () => $controller->update('alice', 'calendar');
		$response = $unusable ? UnusableWriter::during($writer, $call) : $call();
		return $transcript->record($step, ['route' => 'scope#update', 'user' => 'root', 'uid' => 'alice', 'module' => 'calendar', 'body' => $body],
			$response);
	}

	/**
	 * @param PolicyWriter $writer the writer of the request
	 * @param bool $open whether the scope barrier is open
	 * @return ScopeController the controller of one request, as the container builds it
	 */
	private function controller(PolicyWriter $writer, bool $open): ScopeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => array_key_exists($key, $this->body)
			? $this->body[$key] : $default);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->fx->user('root'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000123);
		return new ScopeController('mcp', $request, $this->fx->scopes, $this->fx->enumerator(), $writer, $this->fx->state,
			new ScopeActivation(['files' => $open, 'calendar' => $open, 'tasks' => $open]), $this->fx->userManager(), $session,
			new NullLogger(), $time);
	}

	/** @return array<string, mixed> revision, the restricted scopes (one row each, with the revision that saved it), their items and the policy event kinds */
	private function state(): array {
		$dbal = $this->fx->database->dbal;
		return [
			'revision' => $this->fx->revision(),
			'modes' => $dbal->fetchAllAssociative('SELECT user_id, module, policy_rev FROM ' . ResourceScopeMapper::MODES_TABLE . ' ORDER BY user_id, module'),
			// Items are keyed by a hash of the user; alice is the only user of this flow.
			'items' => $dbal->fetchAllAssociative('SELECT module, resource_id FROM ' . ResourceScopeMapper::ITEMS_TABLE
				. ' ORDER BY module, resource_id'),
			'events' => array_column($this->fx->eventRows(), 'kind'),
		];
	}
}
