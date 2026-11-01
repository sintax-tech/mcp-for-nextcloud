<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\GrantsController;
use OCA\Mcp\Access\Grants\GrantMatrix;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\OAuth\OAuthClientsService;
use OCA\Mcp\Access\Policy\GroupGrantsService;
use OCA\Mcp\Access\Policy\GroupSourceClassifier;
use OCA\Mcp\Access\Policy\PolicyOperationContext;
use OCA\Mcp\Access\Policy\PolicyResolver;
use OCA\Mcp\Tests\Unit\Service\Policy\GroupLifecycleDatabase;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the group policy routes (`grants#previewGroup`, `grants#saveGroup`, RE-01): the real
 * {@see GrantsController} over the real {@see GroupGrantsService} and {@see \OCA\Mcp\Access\Policy\PolicyWriter} on
 * SQLite. The directory is a double (the group can grant or not; ana and bob are its members) and so is the resolution
 * of one member, which is built from the stored group rows exactly as GroupGrantsWriterTest does. The effect is read
 * from the database: the grant rows and lifecycle of the group, the policy revision, and what ana inherits from it.
 */
final class GroupGrantsEffectsContractTest extends TestCase {
	use GroupLifecycleDatabase;

	/** The group of every step. */
	private const GID = 'staff';
	/** The set every save previews. */
	private const GRANTS = ['_system' => ['eligible'], 'notes' => ['edit']];

	private bool $trusted = true;
	/** @var array<string, mixed> JSON body of the next request */
	private array $body = [];
	/** @var array<string, string> random ids seen so far => placeholder */
	private array $ids = [];

	/** @return void the SQLite policy database of the group lifecycle tests */
	protected function setUp(): void {
		$this->lifecycleDatabase();
	}

	/** @return void preview and save of a group's policy: every refusal writes nothing, the save stores the set */
	public function testPreviewAndSaveOfAGroupPolicy(): void {
		$transcript = new ControllerTranscript($this, 'grants.group-policy', fn (): array => $this->state());
		$this->send($transcript, 'preview without expectedRevision: 428', 'previewGroup', ['gid' => self::GID, 'grants' => self::GRANTS]);
		$this->send($transcript, 'preview with a non-string group: 400', 'previewGroup', ['expectedRevision' => $this->revision(), 'gid' => 42]);
		$this->trusted = false;
		$this->send($transcript, 'a group that cannot grant: 422', 'previewGroup', $this->previewBody());
		$this->trusted = true;
		$preview = $this->send($transcript, 'preview: nothing is written', 'previewGroup', $this->previewBody());
		$previewId = (string)($preview['json']['previewId'] ?? '');
		$save = ['expectedRevision' => $this->revision(), 'previewId' => $previewId, 'gid' => self::GID, 'grants' => self::GRANTS];
		$this->send($transcript, 'save of an unknown preview: 404', 'saveGroup', ['previewId' => str_repeat('0', 32) . '-1'] + $save);
		$this->send($transcript, 'save without previewId: 400', 'saveGroup', ['previewId' => null] + $save);
		$this->send($transcript, 'save with an outdated revision: 409, nothing written', 'saveGroup', ['expectedRevision' => '999'] + $save);
		$this->send($transcript, 'writer unusable: 503, nothing written', 'saveGroup', $save, true);
		$before = $this->state();
		$saved = $this->send($transcript, 'save: the group holds the previewed set', 'saveGroup', $save);
		$this->assertSame(200, $saved['status']);
		$this->assertNotEquals($before['rows'], $transcript->lastState()['rows'], 'the save stored nothing');
		$this->assertSame([['_system', 'eligible'], ['notes', 'edit']], $transcript->lastState()['rows'][self::GID]);
		$this->assertTrue($transcript->lastState()['ana inherits eligibility'], 'the member does not inherit the saved set');
		$this->send($transcript, 'the same preview again: refused, nothing written', 'saveGroup', ['expectedRevision' => $this->revision()] + $save);
		foreach ($transcript->lastState()['life'] as $row) {
			$this->register($transcript, (string)$row['gen']);
		}
		$transcript->assertGolden();
	}

	/**
	 * @param ControllerTranscript $transcript the transcript of the flow
	 * @param string $step what the step proves
	 * @param string $method previewGroup or saveGroup
	 * @param array<string, mixed> $body JSON body of the request
	 * @param bool $unusable whether the policy writer is unusable during the request
	 * @return array<string, mixed> the recorded answer
	 */
	private function send(ControllerTranscript $transcript, string $step, string $method, array $body, bool $unusable = false): array {
		$this->body = $body;
		$controller = $this->controller();
		$call = static fn () => $controller->$method();
		$response = $unusable ? UnusableWriter::during($this->writer, $call) : $call();
		$answer = $transcript->record($step, ['route' => 'grants#' . $method, 'user' => 'root', 'body' => $body], $response);
		// Every preview id, sent or answered (a 409 carries a fresh preview), is random: shape checked, then replaced.
		array_walk_recursive($answer, function (mixed $value, string|int $key) use ($transcript): void {
			if ($key === 'previewId' && is_string($value)) {
				$this->assertMatchesRegularExpression('/^[0-9a-f]{32}-[1-9][0-9]*$/', $value);
				$this->register($transcript, $value);
			}
		});
		if (is_string($body['previewId'] ?? null)) {
			$this->register($transcript, $body['previewId']);
		}
		return $answer;
	}

	/** @return array<string, mixed> the preview body of the group's set at the current revision */
	private function previewBody(): array {
		return ['expectedRevision' => $this->revision(), 'gid' => self::GID, 'grants' => self::GRANTS];
	}

	/**
	 * @param ControllerTranscript $transcript the transcript of the flow
	 * @param string $id a random id the code stored or answered
	 * @return void
	 */
	private function register(ControllerTranscript $transcript, string $id): void {
		$this->ids[$id] ??= '<random-id#' . (count($this->ids) + 1) . '>';
		$transcript->literal($id, $this->ids[$id]);
	}

	/** @return GrantsController the controller of one request, as the container builds it for the group routes */
	private function controller(): GrantsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => array_key_exists($key, $this->body)
			? $this->body[$key] : $default);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$classifier = $this->createMock(GroupSourceClassifier::class);
		$classifier->method('trusted')->willReturnCallback(fn (): bool => $this->trusted);
		$classifier->method('members')->willReturn([hash('sha256', 'ana') => 'ana', hash('sha256', 'bob') => 'bob']);
		$groups = new GroupGrantsService($this->writer, $classifier, $this->resolver(), $this->grants, $this->life);
		return new GrantsController('mcp', $request, $this->createMock(GrantMatrix::class), $this->createMock(GrantPolicy::class),
			$this->createMock(IUserManager::class), $this->createMock(IConfig::class), $this->createMock(LogsAccess::class), $this->writer,
			$this->state, $session, $groups, $this->createMock(OAuthClientsService::class));
	}

	/** @return PolicyResolver resolutions over the stored rows and lifecycles of the group, for ana and bob */
	private function resolver(): PolicyResolver {
		$resolver = $this->createMock(PolicyResolver::class);
		$resolver->method('begin')->willReturnCallback(function (string $uid, array $added, array $removed): PolicyOperationContext {
			$gids = in_array($uid, ['ana', 'bob'], true) ? array_values(array_diff([self::GID], $removed)) : [];
			$lives = [];
			foreach ($this->life->forGroups($gids) as $row) {
				$lives[$row['groupId']] = $row;
			}
			$candidates = [];
			foreach ($this->grants->forGroups($gids) as $row) {
				if ($row['grants'] !== []) {
					$candidates[] = $row + ['trusted' => true, 'state' => $lives[$row['groupId']]['state'] ?? null,
						'gen' => $lives[$row['groupId']]['gen'] ?? null];
				}
			}
			return new PolicyOperationContext($this->revision(), $this->writer, [], $candidates, true, true, [], true, true,
				\OCA\Mcp\Access\ResourceScope\ScopeGuard::read($this->createMock(\OCA\Mcp\Db\ResourceScopeMapper::class), null));
		});
		return $resolver;
	}

	/** @return array<string, mixed> group rows, lifecycles, revision, pending events and what ana inherits now */
	private function state(): array {
		$rows = [];
		foreach ($this->grants->forGroups([self::GID]) as $row) {
			foreach ($row['grants'] as $module => $operations) {
				foreach ($operations as $operation) {
					$rows[$row['groupId']][] = [$module, $operation];
				}
			}
		}
		$context = $this->resolver()->begin('ana', [], [], false);
		try {
			$inherits = $context->inheritedEligibility()->allowed === true;
		} finally {
			$context->close();
		}
		return ['rows' => $rows, 'life' => array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['groupId', 'state', 'gen'])),
			$this->life->forGroups([self::GID])), 'revision' => $this->revision(), 'ana inherits eligibility' => $inherits];
	}
}
