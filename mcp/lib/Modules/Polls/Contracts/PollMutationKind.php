<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Contracts;

/** Closed mutation families whose rules live in the corresponding verb classes. */
enum PollMutationKind {
	case Configuration;
	case Options;
	case Shares;
}
