<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Sharing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCP\Constants;

/**
 * The two permission levels the model and the person speak, and the bits the core stores for a share.
 *
 * - `view` is READ, on a file and on a folder.
 * - `edit` on a file is READ+UPDATE; on a folder it is READ+UPDATE+CREATE+DELETE, because changing a folder
 *   means adding and removing what is inside it.
 *
 * {@see self::bits()} never writes PERMISSION_SHARE: re-sharing without the owner knowing is out of the 0.10.
 * The consequence for a share created in the web interface, which carries SHARE (31 on a folder, 19 on a file),
 * is that updating it through bits() takes the re-share right away. {@see self::reshare()} tells whether the
 * current share has it, so a plan can say so before the update.
 *
 * {@see self::classify()} reads existing bits back ignoring SHARE, so a web-made share still reads as `view` or
 * `edit`; any other combination is `custom`. On a public link the SHARE bit is what the core adds when outgoing
 * federation is allowed (the link can be added to another cloud), not a re-share by a person.
 *
 * Stateless: every method is static and the class is never instantiated.
 */
final class SharePermission {
    /** Read only. */
    public const VIEW = 'view';
    /** Read and change; on a folder also create and delete inside it. */
    public const EDIT = 'edit';
    /** Existing bits that match neither level, as only a share made elsewhere can have. */
    public const CUSTOM = 'custom';
    /** Levels a caller may ask for, in the order the schema lists them. */
    public const LEVELS = [self::VIEW, self::EDIT];

    /** Bits of `edit` on a file. */
    private const EDIT_FILE = Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE;
    /** Bits of `edit` on a folder. */
    private const EDIT_FOLDER = self::EDIT_FILE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE;

    private function __construct() {}

    /**
     * The bits to store for a level. The core still caps them to what the node itself allows.
     *
     * @param string $level one of {@see self::LEVELS}
     * @param bool $folder whether the shared node is a folder
     * @return int OCP\Constants permission bits, never including PERMISSION_SHARE
     * @throws ArgumentValidationException for any other level, with field `permission` and without the value
     */
    public static function bits(string $level, bool $folder): int {
        return match ($level) {
            self::VIEW => Constants::PERMISSION_READ,
            self::EDIT => $folder ? self::EDIT_FOLDER : self::EDIT_FILE,
            default => throw new ArgumentValidationException('Invalid argument: permission', 'permission',
                Translator::t('one of: %s', [implode(', ', self::LEVELS)])),
        };
    }

    /**
     * The level existing bits stand for, ignoring PERMISSION_SHARE.
     *
     * @param int $bits permissions of an existing share
     * @param bool $folder whether the shared node is a folder
     * @return string {@see self::VIEW}, {@see self::EDIT} or {@see self::CUSTOM}
     */
    public static function classify(int $bits, bool $folder): string {
        return match ($bits & ~Constants::PERMISSION_SHARE) {
            Constants::PERMISSION_READ => self::VIEW,
            $folder ? self::EDIT_FOLDER : self::EDIT_FILE => self::EDIT,
            default => self::CUSTOM,
        };
    }

    /**
     * Whether the bits carry PERMISSION_SHARE: for a person or a group, the recipient may share it on; for a
     * public link, outgoing federation is allowed for it.
     *
     * @param int $bits permissions of an existing share
     * @return bool true when PERMISSION_SHARE is set
     */
    public static function reshare(int $bits): bool {
        return ($bits & Constants::PERMISSION_SHARE) !== 0;
    }
}
