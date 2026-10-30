<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

require_once __DIR__ . '/../../Tools/Polls/NativePollsDouble.php';

use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateScene;
use OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateTestFactory;
use OCA\Mcp\Tests\Unit\Tools\Polls\NativeScene;
use OCA\Mcp\Tests\Unit\Tools\Polls\PollsContentTestFactory;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\Polls\Create\CreateAdmission;
use OCA\Mcp\Tools\Polls\Create\CreateFlow;
use OCA\Mcp\Tools\Polls\Create\CreateNative;
use OCA\Mcp\Tools\Polls\Create\CreateNormalizer;
use OCA\Mcp\Tools\Polls\Create\CreatePlan;
use OCA\Mcp\Tools\Polls\Create\CreateSurface;
use OCA\Mcp\Tools\Polls\Create\CreateWriter;
use OCA\Mcp\Tools\Polls\Handler\CreatePollHandler;
use OCA\Mcp\Tools\Polls\Handler\ListPollsHandler;
use OCA\Mcp\Tools\Polls\Handler\ReadPollHandler;
use OCA\Mcp\Tools\Polls\PollsContext;
use OCA\Mcp\Tools\Polls\PollsCursor;
use OCA\Mcp\Tools\Polls\PollsListing;
use OCA\Mcp\Tools\Polls\PollsModule;
use OCA\Mcp\Tools\Polls\PollsReading;
use OCA\Mcp\Tools\Polls\PollsServices;
use OCA\Mcp\Tools\Polls\PollsSessionGuard;
use OCA\Mcp\Tools\Polls\PollsShapes;
use OCA\Mcp\Tools\Polls\PollsSurface;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Effect contract of the Polls module (RE-01): creating a text poll with two options, frozen through the endpoint with
 * the strict native Polls doubles (the stored polls and options, and every native stage) as the observable effect,
 * behind the real admission, which re-reads the gate's GrantPolicy.
 */
final class PollsEffectsContractTest extends TestCase {
	/** Installed Polls version, changed by the PlanChanged step. */
	private string $version = '9.3.0';
	private EffectsGate $gate;
	private IUserSession $session;

	/** @return void builds the native Polls scene and the endpoint */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn(EffectsGate::USER);
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($alice);
		$this->session->method('isLoggedIn')->willReturn(true);
		NativeScene::reset();
		NativeScene::$services = NativeCreateTestFactory::services($this, new \OCA\Polls\UserSession($this->session));
		NativeCreateScene::reset();
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return PollsModule the module of one request, wired as PollsTestRig and PollsCreateRig wire it, over the gate's policy */
	private function module(): PollsModule {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(EffectsGate::USER);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === EffectsGate::USER ? $user : null);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$apps->method('getAppVersion')->willReturnCallback(fn (): string => $this->version);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $name): object => NativeScene::$services[$name]
			?? throw new \LogicException('Undeclared native dependency ' . $name));
		$policy = $this->gate->policy();
		$services = new PollsServices($apps, $users, $container, $policy, new PollsSurface());
		$guard = new PollsSessionGuard($this->session);
		$logger = $this->createMock(LoggerInterface::class);
		$context = new PollsContext($services, $guard, $logger);
		$shapes = new PollsShapes();
		$cursor = new PollsCursor(new PlanState($this->gate->config()));
		$native = new CreateNative($services, $guard, new CreateAdmission($apps, $users, $policy, new PollsSurface()), new CreateSurface());
		$normalizer = new CreateNormalizer();
		$create = new CreateFlow($native, $normalizer, new CreatePlan(new PlanState($this->gate->config())), new CreateWriter($native, $normalizer), $logger);
		return new PollsModule(new ListPollsHandler($context, new PollsListing($services, $guard, $shapes, $cursor)),
			new ReadPollHandler($context, new PollsReading($services, $guard, $shapes, $cursor)),
			...[...PollsContentTestFactory::handlers($this, $context, $services, $guard, $cursor), new CreatePollHandler($create)]);
	}

	/** @return void polls_create_poll with two options: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testCreatingAPoll(): void {
		$flow = (new CriticalFlow('create-poll', 'polls_create_poll', 'polls', 'create',
			['type' => 'textPoll', 'title' => 'Almoço de sexta', 'options' => [['text' => 'Pizza'], ['text' => 'Sushi']]],
			fn (): array => $this->state()))
			// Polls was updated between the plan and the confirmation: the plan named the version it would write through.
			->changing(function (): void {
				$this->version = '9.3.1';
			})
			->withoutVisibility('Polls live in the Polls database, not in Files: no system tag or excluded folder can hide a poll from MCP');
		(new FlowRunner($this, $this->gate, 'polls'))->run($flow);
	}

	/** @return array{polls: array<int, array<string, mixed>>, options: array<int, list<array<string, mixed>>>, stages: list<string>} what Polls holds and every native stage */
	private function state(): array {
		return ['polls' => NativeScene::$polls, 'options' => NativeScene::$options, 'stages' => NativeCreateScene::$effects];
	}
}
