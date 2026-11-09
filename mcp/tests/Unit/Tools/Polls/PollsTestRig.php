<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Polls\Integration\PollsServices;
use OCA\Mcp\Modules\Polls\Integration\PollsSessionGuard;
use OCA\Mcp\Modules\Polls\Integration\PollsSurface;
use OCA\Mcp\Modules\Polls\PollsModule;
use OCA\Mcp\Modules\Polls\Presentation\PollsShapes;
use OCA\Mcp\Modules\Polls\Reading\ListPollsHandler;
use OCA\Mcp\Modules\Polls\Reading\PollsContext;
use OCA\Mcp\Modules\Polls\Reading\PollsCursor;
use OCA\Mcp\Modules\Polls\Reading\PollsListing;
use OCA\Mcp\Modules\Polls\Reading\PollsReading;
use OCA\Mcp\Modules\Polls\Reading\ReadPollHandler;
use OCP\{IUser,IUserManager,IUserSession};
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** Builds the actual PO-1 module with strict native-shape doubles and the real policy/MAC. */
final class PollsTestRig {
	/** @var IUserSession endpoint session */
	public IUserSession $session;
	/** @var string|null endpoint actor, mutable only to model endpoint context changes in tests */
	public ?string $uid = 'alice';
	/** @var \OCA\Mcp\Access\Grants\GrantPolicy real grant policy */
	public \OCA\Mcp\Access\Grants\GrantPolicy $policy;
	/** @var PollsModule actual module */
	public PollsModule $module;
	/** @var \ArrayObject<string, object> tool instances the module resolves */
	public \ArrayObject $tools;
	/** @var PollsServices actual resolver */
	public PollsServices $services;
	/** @var bool app enablement */
	public bool $enabled = true;
	/** @var string installed release */
	public string $version = '8.6.3';
	/** @var list<array<string,mixed>> sanitized diagnostic contexts */
	public array $logs = [];
	/** @var list<string> Final interpolated diagnostic messages. */
	public array $logMessages = [];

	/**
	 * Builds the module without permissive magic calls to native services.
	 * @param TestCase $test mock factory
	 * @return void
	 */
	public function __construct(TestCase $test) {
		$this->tools = new \ArrayObject();
		require_once __DIR__ . '/NativePollsDouble.php';
		NativeScene::reset();
		$users = [];
		foreach (['alice','bob','carol'] as $uid) {
			$user = $test->getMockBuilder(IUser::class)->getMock();
			$user->method('getUID')->willReturn($uid);
			$users[$uid] = $user;
		}
		$this->session = $test->getMockBuilder(IUserSession::class)->getMock();
		$this->session->method('getUser')->willReturnCallback(fn (): ?IUser => $users[$this->uid] ?? null);
		$this->session->method('isLoggedIn')->willReturnCallback(fn (): bool => $this->uid !== null);
		$manager = $test->getMockBuilder(IUserManager::class)->getMock();
		$manager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $users[$uid] ?? null);
		$apps = $test->getMockBuilder(IAppManager::class)->getMock();
		$apps->method('isEnabledForUser')->willReturnCallback(fn (): bool => $this->enabled);
		$apps->method('getAppVersion')->willReturnCallback(fn (): string => $this->version);
		$config = (new InMemoryConfig())->mock($test);
		$this->policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		$native = new \OCA\Polls\UserSession($this->session);
		NativeScene::$services = NativeCreateTestFactory::services($test,$native);
		$container = $test->getMockBuilder(ContainerInterface::class)->getMock();
		$container->method('get')->willReturnCallback(static function (string $name): object {
			if (!isset(NativeScene::$services[$name])) {
				throw new \LogicException('Undeclared native dependency ' . $name);
			}
			return NativeScene::$services[$name];
		});
		$this->services = new PollsServices($apps, $manager, $container, $this->policy, new PollsSurface());
		$guard = new PollsSessionGuard($this->session);
		$logger = $test->getMockBuilder(\Psr\Log\LoggerInterface::class)->getMock();
		$logger->method('error')->willReturnCallback(function ($message, array $context): void {
			$this->logs[] = $context;
			$replace = [];
			foreach ($context as $key => $value) { $replace['{' . $key . '}'] = (string)$value; }
			$this->logMessages[] = strtr($message, $replace);
		});
		$context = new PollsContext($this->services, $guard, $logger);
		$shapes = new PollsShapes();
		$cursor = new PollsCursor(new PlanState($config));
		$flow = $test->getMockBuilder(\OCA\Mcp\Modules\Polls\Creation\CreateFlow::class)->disableOriginalConstructor()->getMock();
		$this->module = PollsTestModule::build($this->tools, new ListPollsHandler($context, new PollsListing($this->services,$guard,$shapes,$cursor)),
			new ReadPollHandler($context,new PollsReading($this->services,$guard,$shapes,$cursor)),
			PollsContentTestFactory::handlers($test, $context, $this->services, $guard, $cursor),
			new \OCA\Mcp\Modules\Polls\Creation\CreatePollHandler($flow), new \OCA\Mcp\Modules\Polls\Creation\CreatePollPreview($flow));
	}

	/**
	 * Adds a raw native row with independent ACL metadata and stable created time.
	 * @param int $id native id
	 * @param string $owner owner UID
	 * @param array<string,mixed> $overrides native values
	 * @return void
	 */
	public function poll(int $id, string $owner = 'alice', array $overrides = []): void {
		NativeScene::$polls[$id] = $overrides + ['id'=>$id,'owner'=>$owner,'created'=>100,'title'=>'Title '.$id,
			'description'=>'Description','access'=>'private','deleted'=>0,'members'=>[],'delegates'=>[]];
	}

	/**
	 * Adds a raw native option, deliberately carrying fields discarded by the whitelist.
	 * @param int $id option id
	 * @param int $pollId parent
	 * @param array<string,mixed> $overrides native values
	 * @return void
	 */
	public function option(int $id, int $pollId = 1, array $overrides = []): void {
		NativeScene::$options[$pollId][] = $overrides + ['id'=>$id,'pollId'=>$pollId,'text'=>'Option '.$id,'order'=>$id,
			'timestamp'=>0,'duration'=>0,'confirmed'=>0,'deleted'=>0,'locked'=>false];
	}

	/**
	 * Calls and decodes the actual module response.
	 * @param string $tool tool name
	 * @param array<string,mixed> $arguments input
	 * @param string $actor explicit authenticated caller
	 * @return array<string,mixed> response data
	 */
	public function call(string $tool = 'polls_list_polls', array $arguments = [], string $actor = 'alice'): array {
		$result = $this->module->call($tool,$arguments,$actor);
		return json_decode($result['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
	}
}
