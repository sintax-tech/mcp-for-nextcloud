<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Controller;

use OCA\Mcp\Controller\TagsController;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

final class TagsControllerTest extends TestCase {
    private InMemoryConfig $config;
    private $tagMapper;
    private $tagManager;
    private $request;
    private VisibilityGuard $guard;

    protected function setUp(): void {
        parent::setUp();
        $this->config = new InMemoryConfig();
        $this->tagMapper = $this->createMock(ISystemTagObjectMapper::class);
        $this->tagManager = $this->createMock(ISystemTagManager::class);
        $this->request = $this->createMock(IRequest::class);
        $this->guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper);
    }

    private function controller(): TagsController {
        return new TagsController('mcp', $this->request, $this->guard, $this->tagManager);
    }

    public function testIndexReturnsTagsWithTypesAndSelected(): void {
        $this->guard->setHiddenTagIds(['10']);

        $tag1 = $this->createMock(ISystemTag::class);
        $tag1->method('getId')->willReturn('10');
        $tag1->method('getName')->willReturn('Confidential');
        $tag1->method('isUserVisible')->willReturn(false);
        $tag1->method('isUserAssignable')->willReturn(false);

        $tag2 = $this->createMock(ISystemTag::class);
        $tag2->method('getId')->willReturn('20');
        $tag2->method('getName')->willReturn('Restricted');
        $tag2->method('isUserVisible')->willReturn(true);
        $tag2->method('isUserAssignable')->willReturn(false);

        $tag3 = $this->createMock(ISystemTag::class);
        $tag3->method('getId')->willReturn('30');
        $tag3->method('getName')->willReturn('Collab');
        $tag3->method('isUserVisible')->willReturn(true);
        $tag3->method('isUserAssignable')->willReturn(true);

        $this->tagManager->method('getAllTags')->willReturn([$tag1, $tag2, $tag3]);

        $res = $this->controller()->index();
        $this->assertSame(Http::STATUS_OK, $res->getStatus());
        $data = $res->getData();

        $this->assertSame(['10'], $data['selected']);
        $this->assertCount(3, $data['tags']);

        $this->assertSame('10', $data['tags'][0]['id']);
        $this->assertSame('invisible', $data['tags'][0]['type']);
        $this->assertTrue($data['tags'][0]['selected']);

        $this->assertSame('20', $data['tags'][1]['id']);
        $this->assertSame('restricted', $data['tags'][1]['type']);
        $this->assertFalse($data['tags'][1]['selected']);

        $this->assertSame('30', $data['tags'][2]['id']);
        $this->assertSame('collaborative', $data['tags'][2]['type']);
        $this->assertFalse($data['tags'][2]['selected']);
    }

    public function testUpdateSavesTags(): void {
        $this->request->method('getParam')->with('tagIds')->willReturn(['10', '30']);
        $res = $this->controller()->update();
        $this->assertSame(Http::STATUS_OK, $res->getStatus());
        $this->assertSame(['10', '30'], $res->getData()['selected']);
        $this->assertSame(['10', '30'], $this->guard->getHiddenTagIds());
    }

    public function testUpdateRejectsNonList(): void {
        $this->request->method('getParam')->with('tagIds')->willReturn('invalid');
        $res = $this->controller()->update();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
    }

    public function testUpdateRejectsNonPositiveInteger(): void {
        $this->request->method('getParam')->with('tagIds')->willReturn(['10', 'invalid']);
        $res = $this->controller()->update();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
    }

    public function testUpdateRejectsZeroOrNegative(): void {
        $this->request->method('getParam')->with('tagIds')->willReturn(['0']);
        $res = $this->controller()->update();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());

        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getParam')->with('tagIds')->willReturn(['-1']);
        $res = $this->controller()->update();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
    }

    public function testUpdateFiltersOutNonExistentTagsWhenTagManagerPresent(): void {
        $tag1 = $this->createMock(ISystemTag::class);
        $tag1->method('getId')->willReturn('10');
        $this->tagManager->method('getTagsByIds')->willReturn(['10' => $tag1]);

        $guardWithManager = new VisibilityGuard($this->config->mock($this), $this->tagMapper, null, $this->tagManager);
        $controller = new TagsController('mcp', $this->request, $guardWithManager, $this->tagManager);

        $this->request->method('getParam')->with('tagIds')->willReturn(['10', '99']);
        $res = $controller->update();
        $this->assertSame(Http::STATUS_OK, $res->getStatus());
        $this->assertSame(['10'], $res->getData()['selected']);
        $this->assertSame(['10'], $guardWithManager->getHiddenTagIds());
    }
}
