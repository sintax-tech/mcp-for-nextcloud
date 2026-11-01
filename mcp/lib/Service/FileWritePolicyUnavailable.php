<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\Tools\Common\FileTypeMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Unreadable policy state; distinct from a denied name and never interpreted as off. */
final class FileWritePolicyUnavailable extends ToolFailure {
	/** Construct a generic failure without retaining backend messages or configuration. */
	public function __construct() {
		parent::__construct(FileTypeMessages::unavailable());
	}
}
