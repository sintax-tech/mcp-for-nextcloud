<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

/** Local phase detected a changed epoch, cause set or single-use state; repeat external admission. */
class SnapshotInvalidated extends \RuntimeException {}
