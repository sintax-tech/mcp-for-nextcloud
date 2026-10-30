<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Tools\Polls\Create\{CreateAdmission,CreateSurface,CreateNative,CreateNormalizer,CreateWriter,CreatePlan,CreateFlow};
use OCA\Mcp\Tools\Polls\{PollsSessionGuard,PollsSurface};
use OCA\Mcp\Tools\Polls\Handler\CreatePollHandler;
use OCA\Mcp\Tools\PlanState;
use OCP\{IUser,IUserManager,IConfig};
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/** Actual production flow, admission, guards, MAC and services; only upstream dependencies are strict fixtures. */
final class PollsCreateRig {
	/** @var PollsTestRig existing read fixture/policy, unchanged predicates */
	public PollsTestRig $base;
	/** @var CreatePollHandler actual handler */
	public CreatePollHandler $handler;
	/** @var CreateNormalizer actual disposable native normalization */
	public CreateNormalizer $normalizer;
	/**
	 * Builds the actual production graph and installs its handler into the actual module for registry/plan tests.
	 * @param TestCase $test mock factory
	 * @return void
	 */
	public function __construct(TestCase $test) {
		$this->base = new PollsTestRig($test); $this->base->version = '9.3.0'; NativeCreateScene::reset();
		$users = $test->getMockBuilder(IUserManager::class)->getMock();
		$users->method('get')->willReturnCallback(function(string $uid) use($test): ?IUser {
			if (!in_array($uid,['alice','bob'],true)) { return null; }
			$user = $test->getMockBuilder(IUser::class)->getMock(); $user->method('getUID')->willReturn($uid); return $user;
		});
		$apps = $test->getMockBuilder(IAppManager::class)->getMock();
		$apps->method('isEnabledForUser')->willReturnCallback(fn():bool=>$this->base->enabled);
		$apps->method('getAppVersion')->willReturnCallback(fn():string=>$this->base->version);
		$this->base->policy->setGrant('alice','polls','create',true);
		$this->base->policy->setGrant('alice','polls','read',false);
		$config = $test->getMockBuilder(IConfig::class)->getMock();$config->method('getSystemValueString')->willReturn('create-test-secret');
		$native = new CreateNative($this->base->services,new PollsSessionGuard($this->base->session),
			new CreateAdmission($apps,$users,$this->base->policy,new PollsSurface()),new CreateSurface());
		$this->normalizer = new CreateNormalizer();
		$logger = $test->getMockBuilder(\Psr\Log\LoggerInterface::class)->getMock();
		$logger->method('error')->willReturnCallback(function($message,array $context):void {$this->base->logs[]=$context;});
		$this->handler = new CreatePollHandler(new CreateFlow($native,$this->normalizer,new CreatePlan(new PlanState($config)),new CreateWriter($native,$this->normalizer),$logger));
		(new \ReflectionProperty($this->base->module,'create'))->setValue($this->base->module,$this->handler);
	}
	/**
	 * Preview and confirmation through actual handlers, never bypassing the MAC.
	 * @param array<string,mixed> $args creation input
	 * @param array<string,mixed>|null $plan prior plan or a fresh preview
	 * @return array<string,mixed> decoded receipt
	 */
	public function confirm(array $args, ?array $plan = null): array {
		$plan ??= $this->handler->preview($args,'alice');
		$response = $this->handler->handle($args+['confirm'=>true,'plan_state'=>$plan['plan_state']],'alice');
		return json_decode($response['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
	}
}
