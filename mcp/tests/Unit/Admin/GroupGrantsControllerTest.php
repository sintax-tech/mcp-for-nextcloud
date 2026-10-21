<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Controller\GrantsController;
use OCA\Mcp\Access\Grants\Groups\GroupGrantsService;
use OCA\Mcp\Access\Grants\Groups\GroupSourceUntrusted;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Receipts\ReceiptExpired;
use OCA\Mcp\Access\Policy\Receipts\ReceiptNotFound;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The group routes of ticket 08 (contracts §2.3): their body, the admin's identity passed on, and each policy error's
 * HTTP answer. The group commands themselves are GroupGrantsWriterTest's.
 */
final class GroupGrantsControllerTest extends TestCase {
    private const PREVIEW_ID = 'ab12ab12ab12ab12ab12ab12ab12ab12-1';
    private MatrixFixture $fx;
    private GroupGrantsService&MockObject $groups;
    /** @var array<string, mixed> */
    private array $body = [];

    protected function setUp(): void {
        $this->fx = new MatrixFixture($this);
        $this->groups = $this->createMock(GroupGrantsService::class);
        $this->groups->method('state')->willReturnCallback(fn (string $gid): array => ['groupId' => $gid, 'trusted' => true, 'state' => null, 'grants' => []]);
    }

    private function controller(array $body): GrantsController {
        $this->body = $body;
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => array_key_exists($key, $this->body) ? $this->body[$key] : $default);
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('root');
        $session = $this->createMock(\OCP\IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return new GrantsController('mcp', $request, $this->fx->matrix(), $this->fx->policy, $this->fx->userManager(),
            $this->fx->config->mock($this), $this->fx->logsAccess(), $this->fx->services()->grants, $this->fx->services()->service, $this->fx->services()->undo, $this->fx->state(),
            $session, $this->groups, $this->fx->oauth, $this->fx->oauthClients());
    }

    private function revision(): string {
        return $this->fx->state()->state()['revision'];
    }

    private function saveBody(array $override = []): array {
        return $override + ['expectedRevision' => $this->revision(), 'previewId' => self::PREVIEW_ID, 'gid' => 'staff',
            'grants' => ['_system' => ['eligible']]];
    }

    public function testTheGroupStateCarriesTheRevisionItWasReadAt(): void {
        $response = $this->controller([])->group('staff');
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['groupId' => 'staff', 'trusted' => true, 'state' => null, 'grants' => [], 'revision' => $this->revision()], $response->getData());
    }

    public function testAPreviewPassesTheBodyAndTheAdministrator(): void {
        $this->groups->expects($this->once())->method('preview')->with($this->revision(), 'root', 'staff', ['notes' => ['edit']])
            ->willReturn(['previewId' => self::PREVIEW_ID]);
        $response = $this->controller(['expectedRevision' => $this->revision(), 'gid' => 'staff', 'grants' => ['notes' => ['edit']]])->previewGroup();
        $this->assertSame([200, ['previewId' => self::PREVIEW_ID]], [$response->getStatus(), $response->getData()]);
    }

    public function testASaveWithoutRevisionIs428AndAnInvalidBody400WithoutCallingTheService(): void {
        $this->groups->expects($this->never())->method('save');
        $this->groups->expects($this->never())->method('preview');
        $this->assertSame(428, $this->controller(['gid' => 'staff', 'previewId' => self::PREVIEW_ID, 'grants' => []])->saveGroup()->getStatus());
        $this->assertSame(428, $this->controller(['gid' => 'staff', 'grants' => []])->previewGroup()->getStatus());
        foreach ([['gid' => 42], ['previewId' => null], ['expectedRevision' => '01']] as $override) {
            $this->assertSame(400, $this->controller($this->saveBody($override))->saveGroup()->getStatus(), json_encode($override));
        }
        $this->assertSame(400, $this->controller(['expectedRevision' => '0', 'gid' => ['staff']])->previewGroup()->getStatus());
    }

    public function testAGroupThatCannotGrantIs422(): void {
        $this->groups->method('save')->willThrowException(new GroupSourceUntrusted());
        $this->groups->method('preview')->willThrowException(new GroupSourceUntrusted());
        foreach ([$this->controller($this->saveBody())->saveGroup(), $this->controller($this->saveBody())->previewGroup()] as $response) {
            $this->assertSame([422, ['error' => 'group_source_untrusted']], [$response->getStatus(), $response->getData()]);
        }
    }

    public function testAnUnknownOrExpiredPreviewIs404Or410(): void {
        $this->groups->method('save')->willReturnOnConsecutiveCalls($this->throwException(new ReceiptNotFound()), $this->throwException(new ReceiptExpired()));
        $this->assertSame([404, ['error' => 'NotFound']], [($r = $this->controller($this->saveBody())->saveGroup())->getStatus(), $r->getData()]);
        $this->assertSame([410, ['error' => 'Gone']], [($r = $this->controller($this->saveBody())->saveGroup())->getStatus(), $r->getData()]);
    }

    /** Contracts §2.3: a divergence observed at save time is a 409 with a new preview of the same set, nothing written. */
    public function testAConflictAnswersTheStoredGroupAndANewPreview(): void {
        $this->groups->method('save')->willThrowException(new PolicyConflict());
        $this->groups->expects($this->once())->method('preview')->with($this->revision(), 'root', 'staff', ['_system' => ['eligible']])
            ->willReturn(['previewId' => 'cd34cd34cd34cd34cd34cd34cd34cd34-1']);
        $response = $this->controller($this->saveBody())->saveGroup();
        $this->assertSame(409, $response->getStatus());
        $this->assertSame(['error' => 'Conflict', 'revision' => $this->revision(), 'state' => [
            'group' => ['groupId' => 'staff', 'trusted' => true, 'state' => null, 'grants' => []],
            'preview' => ['previewId' => 'cd34cd34cd34cd34cd34cd34cd34cd34-1']]], $response->getData());
    }

    public function testAConflictWhoseNewPreviewFailsStillAnswers409(): void {
        $this->groups->method('save')->willThrowException(new PolicyConflict());
        $this->groups->method('preview')->willThrowException(new GroupSourceUntrusted());
        $response = $this->controller($this->saveBody())->saveGroup();
        $this->assertSame(409, $response->getStatus());
        $this->assertNull($response->getData()['state']['preview']);
    }

    public function testAStorageFailureIs503WithItsUncertainty(): void {
        $this->groups->method('save')->willThrowException(new PolicyUnavailable('x', true));
        $response = $this->controller($this->saveBody())->saveGroup();
        $this->assertSame([503, ['error' => 'Unavailable', 'uncertain' => true]], [$response->getStatus(), $response->getData()]);
    }
}
