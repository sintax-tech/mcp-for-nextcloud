<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Reading;

use OCP\Files\Folder;

/** Call-local native metadata, never a persisted cursor, cached grant or authorization token. */
final class ActivityReferenceView {
	/** Resolved only through Platform ActorRoot after Files admission. */
	public ?Folder $root = null;
	/** Null until this call first attempts a Files projection. */
	public ?bool $filesAllowed = null;
	/** Null until this call first attempts a Calendar projection. */
	public ?bool $calendarAllowed = null;
	/** @var array<int,list<\OCA\Mcp\Platform\Dav\Calendar>>|null current scoped collection candidates; event read revalidates the chosen path */
	public ?array $calendars = null;
	/**
	 * @param string $actor authenticated account
	 * @param int|null $folderId only current descendants of this folder, never a stored Activity path
	 */
	public function __construct(public readonly string $actor, public readonly ?int $folderId) {}
}
