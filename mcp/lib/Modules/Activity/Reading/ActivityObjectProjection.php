<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Reading;

/** Explicit known producer routing; unknown or inseparable references omit an entire event without hidden counts. */
final class ActivityObjectProjection {
	/**
	 * @param ActivityObjectShapes $shapes pinned producer identities
	 * @param ActivityFileReferences $files protected current actor tree
	 * @param ActivityCalendarReferences $calendar existing scoped and classified ports
	 */
	public function __construct(private ActivityObjectShapes $shapes, private ActivityFileReferences $files, private ActivityCalendarReferences $calendar) {}
	/**
	 * @param string $actor authenticated caller
	 * @param int|null $folderId explicitly authorized filter, validated before scanning any native events
	 * @return ActivityReferenceView new state for this bounded call only
	 */
	public function begin(string $actor, ?int $folderId): ActivityReferenceView {
		$view = new ActivityReferenceView($actor, $folderId);
		if ($folderId !== null) $this->files->selectFolder($view);
		return $view;
	}
	/**
	 * @param ActivityReferenceView $view current call only
	 * @param array<string,mixed> $row native unformatted event
	 * @return array<string,mixed>|null safe extra fields, empty for reference-free metadata, null to omit
	 */
	public function project(ActivityReferenceView $view, array $row): ?array {
		$shape = $this->shapes->files($row);
		if ($shape !== null) {
			$refs = $this->files->project($view, $shape['ids']);
			return $refs === null ? null : ['action' => $shape['action'], 'references' => $refs];
		}
		if ($view->folderId !== null) return null;
		$shape = $this->shapes->calendar($row);
		if ($shape !== null) {
			$ref = $this->calendar->project($view, $shape['calendarId'], $shape['uid']);
			return $ref === null ? null : ['action' => $shape['action'], 'references' => [$ref]];
		}
		return $this->shapes->metadataOnly($row) ? [] : null;
	}
}
