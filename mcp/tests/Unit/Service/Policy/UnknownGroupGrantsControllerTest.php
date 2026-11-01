<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Service\Policy;

use OCA\Mcp\Tests\Unit\Access\Policy\GroupLifecycleDatabase;

use OCA\Mcp\Controller\UnknownGroupGrantsController;
use OCA\Mcp\Db\GroupGrantRows;
use OCA\Mcp\Access\Policy\UnknownGroupGrants;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Admin and framework protection plus JSON validation against the production cleanup service. */
final class UnknownGroupGrantsControllerTest extends TestCase {
	use GroupLifecycleDatabase;

	/** @return void Prepare the real writer and an opaque grant. */
	protected function setUp(): void {
		$this->lifecycleDatabase();
		$this->policy('team', grants: [['future', 'read']]);
	}

	/** @return UnknownGroupGrants Production diagnosis/cleanup service. */
	private function service(): UnknownGroupGrants {
		return new UnknownGroupGrants(new GroupGrantRows($this->db), $this->state, $this->writer);
	}

	/**
	 * @param array $body Request body.
	 * @param bool $admin Native administrator result.
	 * @return UnknownGroupGrantsController Controller using an authenticated session.
	 */
	private function controller(array $body, bool $admin = true): UnknownGroupGrantsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $body[$key] ?? $default);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('admin')->willReturn($admin);
		return new UnknownGroupGrantsController('mcp', $request, $this->service(), $session, $groups);
	}

	/** @return array Valid confirmation from an authorized report. */
	private function body(): array {
		$report = $this->service()->report('team');
		return ['gid' => 'team', 'expectedRevision' => '0', 'ids' => [$report['unknownGrants'][0]['id']],
			'grantSnapshot' => $report['grantSnapshot'], 'confirm' => true];
	}

	/** @return void Neither endpoint is accessible to non-administrators. */
	public function testAdminRequiredBeforeReadOrWrite(): void {
		$this->assertSame(403, $this->controller(['gid' => 'team'], false)->index()->getStatus());
		$this->assertSame(403, $this->controller($this->body(), false)->clear()->getStatus());
		$this->assertSame('0', $this->revision());
		$this->assertSame(['future/read'], $this->rows('team'));
	}

	/** @return void Framework CSRF/admin defaults are retained on the controller and both actions. */
	public function testFrameworkGuardsAreNotDisabled(): void {
		$class = new \ReflectionClass(UnknownGroupGrantsController::class);
		foreach ([$class, $class->getMethod('index'), $class->getMethod('clear')] as $reflection) {
			foreach ($reflection->getAttributes() as $attribute) {
				$this->assertDoesNotMatchRegularExpression('/NoCSRFRequired|NoAdminRequired|PublicPage/', $attribute->getName());
			}
			$this->assertDoesNotMatchRegularExpression('/@NoCSRFRequired|@NoAdminRequired|@PublicPage/', $reflection->getDocComment() ?: '');
		}
	}

	/** @return void Revision and strict confirmation are required and errors expose no internals. */
	public function testValidationConcurrencyAndSuccessfulReadback(): void {
		$body = $this->body();
		$this->assertSame(200, $this->controller(['gid' => 'team'])->index()->getStatus());
		$missing = $body;
		unset($missing['expectedRevision']);
		$this->assertSame(428, $this->controller($missing)->clear()->getStatus());
		foreach ([['confirm' => 'true'], ['ids' => ['files.read']], ['expectedRevision' => 0]] as $override) {
			$this->assertSame(400, $this->controller($override + $body)->clear()->getStatus());
		}
		$this->assertSame(409, $this->controller(['expectedRevision' => '2'] + $body)->clear()->getStatus());
		$response = $this->controller($body)->clear();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $response->getData()['report']['unknownGrants']);
		$this->assertSame('1', $this->revision());
	}
}
