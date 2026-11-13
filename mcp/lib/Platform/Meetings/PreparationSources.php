<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Meetings;

/**
 * The read ports a meeting dossier may call.
 *
 * Calendar is required: the dossier is about an event, and a missing binding fails construction. Mail, Deck and
 * files are optional. A missing binding leaves that accessor null and drops the source from {@see self::available()},
 * so the dossier is partial and the container still starts. A null source is not a grant: the call never runs.
 */
final class PreparationSources {
	/**
	 * @param MeetingEventRead $events the calendar read; required
	 * @param MeetingMailRead|null $mail the mail search, or null when that port is not bound
	 * @param MeetingDeckRead|null $deck the Deck reads, or null when that port is not bound
	 * @param MeetingFileRead|null $files the file read, or null when that port is not bound
	 */
	public function __construct(
		private MeetingEventRead $events,
		private ?MeetingMailRead $mail = null,
		private ?MeetingDeckRead $deck = null,
		private ?MeetingFileRead $files = null,
	) {
	}

	/**
	 * Sources whose port is bound, in dossier order. An omitted name was not consulted; the list carries no count
	 * of objects the actor cannot see.
	 *
	 * @return list<string> calendar, then whichever of mail, deck and files are bound
	 */
	public function available(): array {
		$sources = ['calendar'];
		if ($this->mail !== null) {
			$sources[] = 'mail';
		}
		if ($this->deck !== null) {
			$sources[] = 'deck';
		}
		if ($this->files !== null) {
			$sources[] = 'files';
		}
		return $sources;
	}

	/**
	 * @return MeetingEventRead the calendar read
	 */
	public function events(): MeetingEventRead {
		return $this->events;
	}

	/**
	 * @return MeetingMailRead|null the mail search, or null when mail is not part of this dossier
	 */
	public function mail(): ?MeetingMailRead {
		return $this->mail;
	}

	/**
	 * @return MeetingDeckRead|null the Deck reads, or null when Deck is not part of this dossier
	 */
	public function deck(): ?MeetingDeckRead {
		return $this->deck;
	}

	/**
	 * @return MeetingFileRead|null the file read, or null when files are not part of this dossier
	 */
	public function files(): ?MeetingFileRead {
		return $this->files;
	}
}
