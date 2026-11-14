<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Collectives;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\TeamsGateway;
use OCA\Mcp\Modules\Collectives\Integration\TeamsSession;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;

/**
 * The member tools of C04b in the process of the cell's Nextcloud 34 to 35 with Collectives and Teams, inside one
 * request, like {@see CollectiveFoundingSessionTest}: the Teams manager the gateway uses is the one Collectives uses,
 * every native failure that happens after a Teams session was opened leaves no current user and no bypass behind, and
 * resolving a group seals the session for the rest of the request (`FederatedUserService::getGroupCircle` sets the
 * Teams app as the current app, which `stopSession` does not clear). Each failure is placed after the session opens:
 * an unknown team or member inside the actor's session, a Collectives read that opens the session and then fails
 * (injected through the container, as `CircleHelper` does when its query throws), and an addition whose group was
 * resolved (current app set) before Teams refused the unknown team. A fresh session and gateway are built per test,
 * so the seal of one test never reaches another. The private state is read by test-only reflection.
 */
#[Group('collectives')]
final class TeamsGatewaySessionTest extends IntegrationTestCase {
	private TeamsSession $session;
	private TeamsGateway $gateway;

	/** @return void runs only in a cell provisioned with Collectives on Nextcloud 34 to 35 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_COLLECTIVES') !== '1' || !in_array((int)\OCP\Util::getVersion()[0], [34, 35], true)) {
			$this->markTestSkipped('Collectives cell on Nextcloud 34 to 35 only (MCP_IT_WITH_COLLECTIVES=1)');
		}
		$container = Server::get(ContainerInterface::class);
		$this->session = new TeamsSession($container);
		$this->gateway = new TeamsGateway($container, $this->session);
	}

	/**
	 * @param object $object an object
	 * @param string $property one of its private properties
	 * @return mixed the value, read without changing it
	 */
	private static function read(object $object, string $property): mixed {
		return (new \ReflectionProperty($object, $property))->getValue($object);
	}

	/** @return object the shared identity service of the Teams manager */
	private function identities(): object {
		return self::read(Server::get('OCA\\Circles\\CirclesManager'), 'federatedUserService');
	}

	/** @param string $when what just failed @return void no Teams user and no bypass are left in the request */
	private function assertClean(string $when): void {
		$this->assertNull(self::read($this->identities(), 'currentUser'), "$when: no Teams user is left");
		$this->assertFalse(self::read($this->identities(), 'bypass'), "$when: no bypass is left");
	}

	/**
	 * @param \Closure(): mixed $call a native call through the gateway that must fail
	 * @return \Throwable what it threw
	 */
	private function failing(\Closure $call): \Throwable {
		try {
			$call();
		} catch (\Throwable $e) {
			return $e;
		}
		$this->fail('the native call did not fail');
	}

	/** @return void the gateway reads the team of the cell's collective through the manager Collectives uses */
	public function testTheGatewayReadsTheTeamCollectivesUses(): void {
		$owner = (string)getenv('MCP_IT_COLLECTIVES_OWNER');
		$this->assertSame(Server::get('OCA\\Circles\\CirclesManager'), self::read(Server::get('OCA\\Collectives\\Service\\CircleHelper'), 'circlesManager'));
		$collective = $this->gateway->collective(NativeTeamsProbe::collectives($owner)['MCP Wiki'], $owner);
		$this->assertGreaterThanOrEqual(8, $collective['actor_level']);
		$this->assertSame('owner', array_column($this->gateway->team($owner, $collective['team_id'])['members'], 'level', 'id')[$owner]);
		$this->assertClean('reading');
	}

	/** @return void native failures after the actor's session opened: an unknown member and team, inside the session */
	public function testFailuresInsideTheSessionLeaveItClosed(): void {
		$owner = (string)getenv('MCP_IT_COLLECTIVES_OWNER');
		$this->failing(fn () => $this->gateway->level($owner, 'no-such-member', 4));
		$this->assertClean('unknown member level');
		$this->failing(fn () => $this->gateway->remove($owner, 'no-such-member'));
		$this->assertClean('unknown member removal');
		$this->assertInstanceOf(ToolFailure::class, $this->failing(fn () => $this->gateway->team($owner, 'no-such-team')));
		$this->assertClean('unknown team');
	}

	/**
	 * @return void a Collectives read that opens a Teams session and fails before closing it, as `CircleHelper` does
	 *     when its query throws: the gateway closes it
	 */
	public function testACollectiveReadThatFailsInsideItsSessionLeavesItClosed(): void {
		$owner = (string)getenv('MCP_IT_COLLECTIVES_OWNER');
		$server = Server::get(ContainerInterface::class);
		$teams = Server::get('OCA\\Circles\\CirclesManager');
		$failing = new class($teams) extends \OCA\Collectives\Service\CollectiveService {
			/** @param object $teams the shared Teams manager */
			public function __construct(private object $teams) {
			}

			/** @return \OCA\Collectives\Db\Collective never: the session is opened, then the query fails */
			public function getCollective(int $id, string $userId): \OCA\Collectives\Db\Collective {
				$this->teams->startSession($this->teams->getLocalFederatedUser($userId));
				throw new \RuntimeException('injected after the Teams session opened');
			}
		};
		$container = new class($server, $failing) implements ContainerInterface {
			/** @param ContainerInterface $server the server container @param object $collectives the failing service */
			public function __construct(private ContainerInterface $server, private object $collectives) {
			}

			/** @return mixed the failing collective service, or what the server resolves */
			public function get(string $id): mixed {
				return $id === 'OCA\\Collectives\\Service\\CollectiveService' ? $this->collectives : $this->server->get($id);
			}

			/** @return bool whether the server resolves it */
			public function has(string $id): bool {
				return $this->server->has($id);
			}
		};
		$gateway = new TeamsGateway($container, new TeamsSession($container));
		$this->assertInstanceOf(ToolFailure::class, $this->failing(fn () => $gateway->collective(NativeTeamsProbe::collectives($owner)['MCP Wiki'], $owner)));
		$this->assertClean('collective read');
	}

	/**
	 * @return void an addition whose group was resolved, which sets the Teams app as current app, then refused by Teams
	 *     for an unknown team: the session is closed and sealed; only the readback of that call opens it again
	 */
	public function testAGroupResolutionSealsTheSession(): void {
		$owner = (string)getenv('MCP_IT_COLLECTIVES_OWNER');
		$team = NativeTeamsProbe::team(NativeTeamsProbe::collectives($owner)['MCP Wiki'], $owner);
		$groups = Server::get(\OCP\IGroupManager::class);
		$created = $groups->get(CollectivesAdminHttpActors::GROUP) === null;
		$group = $created ? $groups->createGroup(CollectivesAdminHttpActors::GROUP) : $groups->get(CollectivesAdminHttpActors::GROUP);
		try {
			$this->failing(fn () => $this->gateway->add($owner, 'no-such-team', TeamsGateway::TYPE_GROUP, CollectivesAdminHttpActors::GROUP));
			$this->assertNotNull(self::read($this->identities(), 'currentApp'), 'the group was resolved before Teams refused the team');
			$this->assertClean('group resolution');
		} finally {
			if ($created) {
				$group->delete();
			}
		}
		$this->assertInstanceOf(ToolFailure::class, $this->failing(fn () => $this->gateway->team($owner, $team)), 'sealed for any other Teams call');
		$this->assertNotSame([], $this->gateway->team($owner, $team, true)['members'], 'the readback of the call still reads, as the actor');
		$this->assertClean('readback');
	}
}
