<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Circles\Model;

/**
 * Native Member constants used by the adapter; behavior is exercised through controlled objects below.
 */
class Member {
	public const LEVEL_NONE = 0;
	public const LEVEL_MEMBER = 1;
	public const TYPE_USER = 1;
	public const TYPE_GROUP = 2;
	public const TYPE_MAIL = 4;
	public const TYPE_CIRCLE = 16;
}
