<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Creation;

/** Fixes creation semantics independently of installed Constants additions. */
final class CreateFormTypes {
	public const SUPPORTED = ['short', 'long', 'color', 'date', 'time', 'linearscale', 'multiple', 'multiple_unique', 'dropdown', 'file', 'grid', 'ranking'];
	public const CHOICES = ['multiple', 'multiple_unique', 'dropdown', 'ranking'];
	public const VERSIONS = ['5.3.0', '5.3.7', '5.4.0', '5.4.1'];
}
