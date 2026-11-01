<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Sharing;

/** Share-manager identity formatting shared by attachment writes and reference filtering. */
final class TalkShareIds {
	/** Native Talk room share provider identifier. */
	private const ROOM_PROVIDER_ID = 'ocRoomShare';

	/**
	 * @param int|string $id Bare share identifier or an already qualified identifier.
	 * @return string Provider-qualified identifier, preserving an existing prefix verbatim.
	 */
	public static function fullShareId(int|string $id): string {
		$id = (string)$id;
		return str_contains($id, ':') ? $id : self::ROOM_PROVIDER_ID . ':' . $id;
	}
}
