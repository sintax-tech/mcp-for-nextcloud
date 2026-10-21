<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Files;

use OCA\Mcp\Modules\Files\Reading\OcrSupport;
use OCA\Mcp\Modules\Files\Reading\TextExtractor;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Tests\Support\Identity\FakeUsers;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/** Only file access and extraction collaborators; editing adds its own write services. */
abstract class FilesReadFixture extends TestCase {
    protected FakeTree $tree;
    protected IRootFolder $root;
    protected IUserManager $users;
    protected IAppManager $apps;
    protected InMemoryConfig $config;
    protected VisibilityGuard $visibility;
    protected NodeAccessInfo $access;
    protected TextExtractor $extractor;
    protected OcrSupport $ocr;
    protected array $tags = [];
    protected array $enabled = ['files_versions'];
    protected bool $ocrActive = false;

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->tree->addFile('/alice/files/Notes/readme.md', "# Notes\né", 'text/markdown');
        $this->root = $this->createMock(IRootFolder::class);
        $this->root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $uid === 'alice'
            ? $this->tree->rootFolder() : throw new \LogicException('Unexpected user'));
        $this->users = FakeUsers::manager($this, FakeUsers::DEFAULTS);
        $this->apps = $this->createMock(IAppManager::class);
        $this->apps->method('isEnabledForUser')->willReturnCallback(fn (string $app) => in_array($app, $this->enabled, true));
        $this->apps->method('isEnabledForAnyone')->willReturnCallback(fn () => $this->ocrActive);
        $this->config = new InMemoryConfig();
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(fn () => $this->tags);
        $mapper->method('assignTags')->willReturnCallback(function ($id, $type, $tags): void {
            $this->tags[(string)$id] = $tags;
        });
        $this->visibility = \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $this->config->mock($this), $mapper, $this->root);
        $this->access = new NodeAccessInfo($this->users, $this->tree->shareManager());
        $temp = $this->createMock(ITempManager::class);
        $temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'mcp'));
        $this->extractor = new TextExtractor($temp);
        $this->ocr = new OcrSupport($this->apps);
    }
}
