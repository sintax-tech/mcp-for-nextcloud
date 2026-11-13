<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status;

use OCA\Mcp\Modules\Status\Tools\ReadOwnStatus;
use OCA\Mcp\Modules\Status\Tools\SetOwnStatusMessage;
use OCA\Mcp\Modules\Status\Tools\SetOwnStatusType;

/** Published status tools, in discovery order. */
final class StatusToolCatalog {
	/** Ordered tools; new tools append here. */
	public const TOOLS = [ReadOwnStatus::class, SetOwnStatusType::class, SetOwnStatusMessage::class];

	/** Static catalog only. */
	private function __construct() {
	}
}
