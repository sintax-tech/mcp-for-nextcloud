<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

/** Native calendar access used by management independently of MCP grants and resource scope. */
interface NativeCalendars {
	/** Scope module for each native calendar component, shared with scoped tool access. */
	public const MODULES = ['VEVENT' => 'calendar', 'VTODO' => 'tasks'];

	/**
	 * @param string $userId Native account whose own calendars are enumerated.
	 * @param string $component Native component (VEVENT or VTODO).
	 * @return list<NativeCalendar> Visible calendars, preserving native order and effective write permission.
	 * @throws \Throwable When native access cannot be evaluated; never replace failure with an empty success.
	 */
	public function visible(string $userId, string $component): array;
}
