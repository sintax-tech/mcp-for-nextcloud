<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\FileTypes;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** A name refused by the policy; HTTP adapters must map this type to 403. */
final class FileTypeDenied extends ToolFailure {
	/** Construct a client-safe refusal, independent of the target name or user role. */
	public function __construct() {
		parent::__construct(FileTypeMessages::denied());
	}
}
