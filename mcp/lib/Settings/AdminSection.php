<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Own entry "MCP for Nextcloud" in the administration settings menu, with the app icon. AdminSettings renders
 * into it. The name is the product name and is not translated.
 */
class AdminSection implements IIconSection {
    /** Section id shared by the admin and personal menus. */
    public const ID = 'mcp';
    /** Product name shown in the menu. */
    public const NAME = 'MCP for Nextcloud';
    /** After the core entries, before the generic app sections. */
    public const PRIORITY = 75;

    /** @param IURLGenerator $urlGenerator resolves the icon path */
    public function __construct(private IURLGenerator $urlGenerator) {}

    /** @return string section id */
    public function getID(): string {
        return self::ID;
    }

    /** @return string menu label */
    public function getName(): string {
        return self::NAME;
    }

    /** @return int position in the menu */
    public function getPriority(): int {
        return self::PRIORITY;
    }

    /** @return string relative URL of the dark (monochrome) app icon, which the menu inverts in dark themes */
    public function getIcon(): string {
        return $this->urlGenerator->imagePath('mcp', 'app-dark.svg');
    }
}
