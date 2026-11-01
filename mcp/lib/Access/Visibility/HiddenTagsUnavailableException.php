<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

/**
 * The system tags could not be checked, so the hidden tags were not saved and the previous ones still apply.
 */
class HiddenTagsUnavailableException extends \RuntimeException {
}
