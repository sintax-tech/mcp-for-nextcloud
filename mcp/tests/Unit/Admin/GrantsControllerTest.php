<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Controller\GrantsController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class GrantsControllerTest extends TestCase {
    private MatrixFixture $fx;
    /** @var array<string, mixed> JSON body parameters seen through IRequest::getParam */
    private array $body = [];

    protected function setUp(): void {
        $this->fx = new MatrixFixture($this);
        $this->fx->addUser('ana', 'Ana Souza', 'ana@corp.example');
        $this->fx->addUser('bob', 'Bob', 'bob@corp.example');
        $this->fx->addUser('carl', 'Carl', 'carl@corp.example', false);
    }

    private function controller(array $body = []): GrantsController {
        $this->body = $body;
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => array_key_exists($key, $this->body) ? $this->body[$key] : $default);
        return new GrantsController('mcp', $request, $this->fx->matrix(), $this->fx->policy, $this->fx->userManager());
    }

    private static function assertBad(JSONResponse $response): void {
        self::assertSame([400, ['error' => 'Invalid request']], [$response->getStatus(), $response->getData()]);
    }

    public function testEveryEndpointIsAdminOnlyAndCsrfProtected(): void {
        foreach (['index', 'update', 'bulk', 'service'] as $method) {
            $this->assertSame([], (new \ReflectionMethod(GrantsController::class, $method))->getAttributes(), $method);
        }
    }

    public function testIndexReturnsTheMatrixPage(): void {
        $response = $this->controller()->index('', '', 1);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['ana', 'bob', 'carl'], array_column($response->getData()['users'], 'uid'));
        $this->assertStringNotContainsString('corp.example', json_encode($response->getData()));
        self::assertBad($this->controller()->index('', 'nope', 1));
        self::assertBad($this->controller()->index('', '', 0));
    }

    public function testToggleEligibilityAndGrantIsReflectedInThePolicy(): void {
        $row = $this->controller(['eligible' => true])->update('ana')->getData();
        $this->assertTrue($row['eligible']);
        $this->assertTrue($this->fx->policy->eligible('ana'));
        $row = $this->controller(['module' => 'files', 'operation' => 'edit', 'granted' => true])->update('ana')->getData();
        $this->assertTrue($row['grants']['files']['edit']);
        $this->assertTrue($this->fx->policy->granted('ana', 'files', 'edit'));
        $this->controller(['module' => 'files', 'operation' => 'read', 'granted' => false])->update('ana');
        $this->assertFalse($this->fx->policy->granted('ana', 'files', 'read'));
        $this->assertFalse($this->fx->policy->granted('bob', 'files', 'edit'));
    }

    public function testUpdateValidatesInput(): void {
        foreach ([
            ['ghost', ['eligible' => true]],
            ['ana', []],
            ['ana', ['eligible' => 'yes']],
            ['ana', ['eligible' => true, 'module' => 'files', 'operation' => 'read', 'granted' => true]],
            ['ana', ['module' => 'files', 'operation' => 'delete', 'granted' => true]],
            ['ana', ['module' => 'mail', 'operation' => 'read', 'granted' => true]],
            ['ana', ['module' => 'files', 'operation' => 'read', 'granted' => 1]],
            ['ana', ['module' => 'files', 'operation' => 'read']],
        ] as [$uid, $body]) {
            self::assertBad($this->controller($body)->update($uid));
        }
        $this->assertSame([], $this->fx->config->user['ana']['mcp'] ?? []);
    }

    public function testBulkUpdatesThePageAtomically(): void {
        $response = $this->controller(['uids' => ['ana', 'bob', 'ana'], 'module' => 'notes', 'operation' => 'create', 'granted' => true])->bulk();
        $this->assertSame(['updated' => 2], $response->getData());
        $this->assertTrue($this->fx->policy->granted('ana', 'notes', 'create'));
        $this->assertTrue($this->fx->policy->granted('bob', 'notes', 'create'));
        $this->controller(['uids' => ['ana', 'bob'], 'module' => null, 'operation' => 'eligible', 'granted' => true])->bulk();
        $this->assertTrue($this->fx->policy->eligible('bob'));

        self::assertBad($this->controller(['uids' => ['ana', 'ghost'], 'module' => 'notes', 'operation' => 'edit', 'granted' => true])->bulk());
        $this->assertFalse($this->fx->policy->granted('ana', 'notes', 'edit'));
        foreach ([
            ['uids' => [], 'module' => 'notes', 'operation' => 'edit', 'granted' => true],
            ['uids' => array_fill(0, 51, 'ana'), 'module' => 'notes', 'operation' => 'edit', 'granted' => true],
            ['uids' => 'ana', 'module' => 'notes', 'operation' => 'edit', 'granted' => true],
            ['uids' => ['ana'], 'module' => 'notes', 'operation' => 'eligible', 'granted' => true],
            ['uids' => ['ana'], 'module' => 'files', 'operation' => 'delete', 'granted' => true],
            ['uids' => ['ana'], 'module' => 'notes', 'operation' => 'edit', 'granted' => 'true'],
        ] as $body) {
            self::assertBad($this->controller($body)->bulk());
        }
    }

    public function testServiceUsesItsOwnKey(): void {
        $this->assertSame(['enabled' => true], $this->controller(['enabled' => true])->service()->getData());
        $this->assertSame('1', $this->fx->config->app['mcp']['service_enabled']);
        $this->assertArrayNotHasKey('enabled', $this->fx->config->app['mcp']);
        self::assertBad($this->controller(['enabled' => '1'])->service());
        $this->assertTrue($this->fx->policy->globalEnabled());
    }
}
