<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Controller;

use OCA\Mcp\Controller\InspectorController;
use OCA\Mcp\Access\Inspection\AccessInspector;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The inspector routes (ticket 13; issue #4 A5/S13, B6): a caller who is not an administrator gets 403 before the
 * target is resolved in any way, and every question an administrator asks is audited with who, whom, what and the
 * answer's code, never a path, a calendar or any other name.
 */
final class InspectorControllerTest extends TestCase {
    private AccessInspector&MockObject $inspector;
    private LoggerInterface&MockObject $logger;
    /** @var list<array{string, array<string, mixed>}> */
    private array $audit = [];

    protected function setUp(): void {
        $this->inspector = $this->createMock(AccessInspector::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->method('info')->willReturnCallback(function (string $message, array $context): void {
            $this->audit[] = [$message, $context];
        });
    }

    private function controller(?string $uid, bool $admin): InspectorController {
        $session = $this->createMock(IUserSession::class);
        $user = null;
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
        }
        $session->method('getUser')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1760000000);
        return new InspectorController('mcp', $this->createMock(IRequest::class), $this->inspector, $session, $groups, $this->logger, $time);
    }

    /** @return array<string, array{\Closure(InspectorController): \OCP\AppFramework\Http\JSONResponse}> */
    public static function questions(): array {
        return [
            'access' => [static fn (InspectorController $c) => $c->access('alice')],
            'path' => [static fn (InspectorController $c) => $c->path('alice', '/Secret/plan.md')],
            'calendar' => [static fn (InspectorController $c) => $c->calendar('alice', 'calendar', '11', 'read')],
        ];
    }

    #[DataProvider('questions')]
    public function testS13ANonAdministratorIsRefusedBeforeTheTargetIsResolved(\Closure $ask): void {
        foreach ([$this->controller('bob', false), $this->controller(null, false)] as $controller) {
            $this->inspector->expects($this->never())->method($this->anything());
            $this->assertSame(403, $ask($controller)->getStatus());
        }
        $this->assertSame([], $this->audit, 'a refused caller asked nothing to audit');
    }

    public function testS13AQuestionIsAuditedWithoutNamesOrPaths(): void {
        $this->inspector->method('path')->with('alice', '/Secret/plan.md')
            ->willReturn(['reason' => 'excluded', 'gate' => 'exclusion', 'exclusion' => 'tag_folder', 'folder' => '/Secret']);
        $response = $this->controller('root', true)->path('alice', '/Secret/plan.md');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('/Secret', $response->getData()['folder'], 'the administrator sees the folder');
        $this->assertSame([['MCP access inspection', ['app' => 'mcp', 'admin' => 'root', 'target' => 'alice', 'action' => 'inspect_path',
            'module' => 'files', 'result' => 'excluded:exclusion:tag_folder', 'timestamp' => 1760000000]]], $this->audit);
        $this->assertStringNotContainsString('Secret', json_encode($this->audit));
        $this->assertStringNotContainsString('plan', json_encode($this->audit));
    }

    public function testTheMatrixAndACalendarAreAuditedByAction(): void {
        $this->inspector->method('effective')->willReturn(['revision' => '3', 'operations' => [[], []]]);
        $this->inspector->method('calendar')->willReturn(['reason' => 'out_of_scope', 'gate' => 'resource_scope']);
        $controller = $this->controller('root', true);
        $controller->access('alice');
        $controller->calendar('alice', 'tasks', '12', 'edit');

        $this->assertSame([['inspect_access', null, 'operations:2'], ['inspect_calendar', 'tasks', 'out_of_scope:resource_scope']],
            array_map(static fn (array $entry): array => [$entry[1]['action'], $entry[1]['module'], $entry[1]['result']], $this->audit));
        $this->assertStringNotContainsString('"12"', json_encode($this->audit), 'no calendar id in the audit');
    }

    public function testInvalidInputIs400AndAnUnreadablePolicyIs503(): void {
        $this->inspector->method('path')->willThrowException(new \InvalidArgumentException('Invalid request'));
        $this->inspector->method('effective')->willThrowException(new PolicyUnavailable());
        $controller = $this->controller('root', true);

        $this->assertSame(400, $controller->path('alice', 'relative')->getStatus());
        $this->assertSame(503, $controller->access('alice')->getStatus());
        $this->assertSame(400, $controller->access('')->getStatus(), 'no user to inspect');
        $this->assertSame(['invalid', 'unavailable'], array_map(static fn (array $entry): string => $entry[1]['result'], $this->audit));
    }

    /** An unexpected failure is audited as an error with its class only, then answered with a generic 500. */
    public function testAnUnexpectedFailureIsAuditedBeforeTheGeneric500(): void {
        $this->inspector->method('path')->willThrowException(new \RuntimeException('disk /alice/files/Secret failed'));
        $errors = [];
        $this->logger->method('error')->willReturnCallback(function (string $message, array $context) use (&$errors): void {
            $errors[] = [$message, $context];
        });

        $response = $this->controller('root', true)->path('alice', '/Secret/plan.md');

        $this->assertSame([500, ['error' => 'Unexpected error']], [$response->getStatus(), $response->getData()]);
        $this->assertSame([['MCP access inspection failed', ['app' => 'mcp', 'admin' => 'root', 'target' => 'alice', 'action' => 'inspect_path',
            'module' => 'files', 'result' => 'error', 'timestamp' => 1760000000, 'exception_class' => \RuntimeException::class]]], $errors);
        $this->assertStringNotContainsString('Secret', json_encode($errors));
    }
}
