<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Reproduces deletion surfaces from the official source trees pinned in delete-sources.json; tokenization only. */
require __DIR__ . '/../../../vendor/autoload.php';
\OCA\Mcp\Tests\Unit\Contract\NextcloudApi\DeleteApiFixtures::generate($argv[1], $argv[2], __DIR__);
