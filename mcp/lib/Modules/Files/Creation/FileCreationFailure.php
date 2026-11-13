<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Unexpected content-write failure after the native sink was attempted; the node may be partial. */
final class FileCreationFailure extends ToolFailure {
}
