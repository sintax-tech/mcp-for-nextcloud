<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Tables\AppInfo;

/** Test-only shape of the Tables application class: only the node type constants, with their values in 1.0.x and 2.x. */
class Application {
	/** Node type of a table in a context. */
	public const NODE_TYPE_TABLE = 0;

	/** Node type of a view in a context. */
	public const NODE_TYPE_VIEW = 1;
}
