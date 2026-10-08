<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\ToolFailure;

/**
 * A new file whose name is already taken, found before the write or by the write itself when another client created
 * the same name in between.
 *
 * A ToolFailure so a tool answers with its message as with any other refusal, and a subclass so the upload route can
 * answer 409 instead of the status of a denied or missing folder.
 */
final class FileExists extends ToolFailure {}
