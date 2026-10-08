<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files\Sharing;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Files\Sharing\SharePermission;
use OCP\Constants;
use PHPUnit\Framework\TestCase;

/** The two levels the model speaks and the permission bits the core stores, in both directions. */
final class SharePermissionTest extends TestCase {
    public function testViewIsReadOnlyForFilesAndFolders(): void {
        self::assertSame(Constants::PERMISSION_READ, SharePermission::bits(SharePermission::VIEW, false));
        self::assertSame(Constants::PERMISSION_READ, SharePermission::bits(SharePermission::VIEW, true));
    }

    public function testEditOnAFileIsReadAndUpdate(): void {
        self::assertSame(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE, SharePermission::bits(SharePermission::EDIT, false));
    }

    public function testEditOnAFolderAlsoCreatesAndDeletes(): void {
        self::assertSame(
            Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE,
            SharePermission::bits(SharePermission::EDIT, true),
        );
    }

    public function testTheShareBitIsNeverWritten(): void {
        foreach (SharePermission::LEVELS as $level) {
            foreach ([false, true] as $folder) {
                self::assertSame(0, SharePermission::bits($level, $folder) & Constants::PERMISSION_SHARE, "$level");
            }
        }
    }

    public function testAnUnknownLevelIsAValidationErrorThatDoesNotEchoTheValue(): void {
        try {
            SharePermission::bits('<b>owner</b>', false);
            self::fail('no exception');
        } catch (ArgumentValidationException $e) {
            self::assertSame(['field' => 'permission', 'rule' => 'one of: view, edit'], $e->details());
            self::assertStringNotContainsString('owner', $e->getMessage() . $e->clientMessage());
        }
    }

    public function testClassifyReadsBackTheTwoLevels(): void {
        self::assertSame(SharePermission::VIEW, SharePermission::classify(Constants::PERMISSION_READ, false));
        self::assertSame(SharePermission::VIEW, SharePermission::classify(Constants::PERMISSION_READ, true));
        self::assertSame(SharePermission::EDIT, SharePermission::classify(SharePermission::bits(SharePermission::EDIT, false), false));
        self::assertSame(SharePermission::EDIT, SharePermission::classify(SharePermission::bits(SharePermission::EDIT, true), true));
    }

    /** The web interface adds SHARE to what it creates (31 on a folder, 19 on a file); that is still edit. */
    public function testClassifyIgnoresTheShareBit(): void {
        self::assertSame(SharePermission::EDIT, SharePermission::classify(Constants::PERMISSION_ALL, true));
        self::assertSame(SharePermission::EDIT, SharePermission::classify(19, false));
        self::assertSame(SharePermission::VIEW, SharePermission::classify(17, true), 'link com o bit do federado');
        self::assertTrue(SharePermission::reshare(Constants::PERMISSION_ALL));
        self::assertFalse(SharePermission::reshare(Constants::PERMISSION_READ));
    }

    public function testAnythingOutsideTheMapIsCustom(): void {
        // Folder with read and create only, file edit bits on a folder, folder edit bits on a file, nothing at all.
        self::assertSame(SharePermission::CUSTOM, SharePermission::classify(Constants::PERMISSION_READ | Constants::PERMISSION_CREATE, true));
        self::assertSame(SharePermission::CUSTOM, SharePermission::classify(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE, true));
        self::assertSame(SharePermission::CUSTOM, SharePermission::classify(15, false));
        self::assertSame(SharePermission::CUSTOM, SharePermission::classify(0, false));
        self::assertSame(SharePermission::CUSTOM, SharePermission::classify(Constants::PERMISSION_UPDATE, false));
    }
}
