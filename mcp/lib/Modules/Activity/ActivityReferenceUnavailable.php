<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity;

use RuntimeException;

/** Uniform refusal for an explicitly requested folder, without exposing its existence or denial reason. */
final class ActivityReferenceUnavailable extends RuntimeException {}
