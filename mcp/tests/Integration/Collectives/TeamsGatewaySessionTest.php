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
 * The member tools of C04b in the process of the cell's Nextcloud 33 to 35 with Collectives and Teams, inside one
 * request, like {@see CollectiveFoundingSessionTest}: the Teams manager the gateway uses is the one Collectives uses,
 * every native failure inside the actor's session leaves no current user and no bypass behind, and resolving a group
 * seals the session for the rest of the request (`FederatedUserService::getGroupCircle` may set the Teams app as the
 * current app, which `stopSession` does not clear). A fresh session and gateway are built per test, so the seal of one
 * test never reaches another. The private state is read by test-only reflection.
 */
#[Group('collectives')]
final class TeamsGatewaySessionTest extends IntegrationTestCase {
	private TeamsSession $session;
	private TeamsGateway $gateway;

	/** @return void runs only in a cell provisioned with Collectives on Nextcloud 33 to 35 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_COLLECTIVES') !== '1' || (int)\OCP\Util::getVersion()[0] < 33) {
			$this->markTestSkipped('Collectives cell on Nextcloud 33 to 35 only (MCP_IT_WITH_COLLECTIVES=1)');
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

	/** @return void native failures inside the actor's session: an unknown collective, member and team */
	public function testFailuresInsideTheSessionLeaveItClosed(): void {
		$owner = (string)getenv('MCP_IT_COLLECTIVES_OWNER');
		$this->assertInstanceOf(ToolFailure::class, $this->failing(fn () => $this->gateway->collective(2147483000, $owner)));
		$this->assertClean('unknown collective');
		$this->failing(fn () => $this->gateway->level($owner, 'no-such-member', 4));
		$this->assertClean('unknown member level');
		$this->failing(fn () => $this->gateway->remove($owner, 'no-such-member'));
		$this->assertClean('unknown member removal');
		$this->assertInstanceOf(ToolFailure::class, $this->failing(fn () => $this->gateway->team($owner, 'no-such-team')));
		$this->assertClean('unknown team');
	}

	/** @return void resolving a group seals the session even when it fails; only the readback of that call opens it again */
	public function testAGroupResolutionSealsTheSession(): void {
		$owner = (string)getenv('MCP_IT_COLLECTIVES_OWNER');
		$team = NativeTeamsProbe::team(NativeTeamsProbe::collectives($owner)['MCP Wiki'], $owner);
		$this->failing(fn () => $this->gateway->add($owner, $team, TeamsGateway::TYPE_GROUP, 'mcp-it-no-such-group-' . bin2hex(random_bytes(3))));
		$this->assertClean('group resolution');
		$this->assertInstanceOf(ToolFailure::class, $this->failing(fn () => $this->gateway->team($owner, $team)), 'sealed for any other Teams call');
		$this->assertNotSame([], $this->gateway->team($owner, $team, true)['members'], 'the readback of the call still reads, as the actor');
		$this->assertClean('readback');
	}
}
