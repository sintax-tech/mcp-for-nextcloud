<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/**
 * Reproduces the Deck LabelService surface from the official sources pinned in label-sources.json; tokenization only.
 * Usage: php generate-labels.php <major> <tree>, where <tree> holds each pinned file at its relative path.
 */
require __DIR__ . '/../../../vendor/autoload.php';
\OCA\Mcp\Tests\Unit\Contract\NextcloudApi\LabelApiFixtures::generate($argv[1], $argv[2], __DIR__);
