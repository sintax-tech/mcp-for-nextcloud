<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Collectives;

use OCA\Mcp\Modules\Collectives\Integration\CollectiveFounding;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proof R2.3 of C04, in the process of the cell's Nextcloud 35 with Collectives 4.7.1 and Teams 35, inside one request:
 * the Teams manager MCP closes is the same object, holding the same identity service, as the one the Collectives
 * helper opens sessions on; and a native failure inside a helper session (a name Teams finds too short, which the
 * helper rethrows as NotPermittedException before its stopSession, `CircleHelper.php:198-213`) leaves no current user
 * and no privileged bypass once the founding call returns. Without the `finally` of TeamsSession the current user
 * stays the actor, which is the mutant this test must catch. The private state is read by test-only reflection.
 */
#[Group('collectives')]
final class CollectiveFoundingSessionTest extends IntegrationTestCase {
	/** @return void runs only in a cell provisioned with Collectives on Nextcloud 35 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_COLLECTIVES') !== '1' || (int)\OCP\Util::getVersion()[0] !== 35) {
			$this->markTestSkipped('Collectives cell on Nextcloud 35 only (MCP_IT_WITH_COLLECTIVES=1)');
		}
	}

	/**
	 * @param object $object an object
	 * @param string $property one of its private properties
	 * @return mixed the value, read without changing it
	 */
	private static function read(object $object, string $property): mixed {
		$reflection = new \ReflectionProperty($object, $property);
		return $reflection->getValue($object);
	}

	/** @return void the shared Teams state is the same objects and is clean after a failure inside a helper session */
	public function testTheSessionTheHelperLeavesOpenIsClosedInTheSameRequest(): void {
		$owner = (string)getenv('MCP_IT_COLLECTIVES_OWNER');
		$manager = Server::get('OCA\\Circles\\CirclesManager');
		$helper = Server::get('OCA\\Collectives\\Service\\CircleHelper');
		$this->assertSame($manager, self::read($helper, 'circlesManager'), 'Collectives opens sessions on the manager MCP closes');
		$identities = self::read($manager, 'federatedUserService');

		$answer = Server::get(CollectiveFounding::class)->create($owner, 'ab', null);

		$this->assertNull($answer['collective_id']);
		$this->assertSame('OCA\\Collectives\\Service\\NotPermittedException', $answer['error']::class);
		$this->assertSame('OCA\\Circles\\Exceptions\\CircleNameTooShortException', $answer['error']->getPrevious()::class);
		$this->assertSame($identities, self::read($manager, 'federatedUserService'));
		$this->assertNull(self::read($identities, 'currentUser'), 'no Teams user is left in the request');
		$this->assertFalse(self::read($identities, 'bypass'), 'no privileged bypass is left in the request');
	}
}
