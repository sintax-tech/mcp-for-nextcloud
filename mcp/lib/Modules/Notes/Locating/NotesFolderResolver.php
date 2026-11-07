<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Locating;

use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * Resolves the notes folder of an account the same way the Notes app does (issue #23).
 *
 * The Notes app keeps its per-user settings as one JSON value (`notes`/`settings`), and `notesPath` is a key inside
 * it. When the user never chose a folder, the app takes its default (`notes`/`defaultFolder`, `Notes` when unset):
 * that name when it exists in the user's files, otherwise its translation in the user's language (`Notizen` in
 * German), which is also the name it creates. This class mirrors that lookup so the MCP tools see the notes the app
 * shows and a creation never adds a second folder next to the app's one. It reads settings only: nothing is written,
 * created, moved or migrated here.
 */
final class NotesFolderResolver {
	/** App id of the Notes app, whose settings and translations are read. */
	public const NOTES_APP = 'notes';
	/** Built-in default folder of the Notes app. */
	public const DEFAULT_FOLDER = 'Notes';

	/**
	 * Receives the user's files, the settings store and, in production, the translation factory.
	 *
	 * @param IRootFolder $rootFolder Files of the account, to check which default folder exists.
	 * @param IConfig $config The Notes app settings (user and app values).
	 * @param IFactory|null $l10n Translations of the Notes app. Null resolves the untranslated default only.
	 * @param IUserManager|null $users Account whose language picks the translation. Null uses the server languages.
	 * @return void
	 */
	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private ?IFactory $l10n = null,
		private ?IUserManager $users = null,
	) {
	}

	/**
	 * The notes folder path of an account, relative to its files, whether the folder exists or not.
	 *
	 * Order: the `notesPath` the Notes app stored in its settings JSON; the older standalone `notes`/`notesPath`
	 * value; then the app's default folder as the app resolves it ({@see self::defaultPath()}).
	 *
	 * An empty `notesPath` in the JSON is a choice, not an absence: picking "/" in the Notes app stores '' and the app
	 * then keeps the notes in the root of the user's files. It is returned as '', which the path guard turns into the
	 * user's folder itself.
	 *
	 * @param string $userId Authenticated user.
	 * @return string Path relative to the user's folder, not yet normalized; '' is the root of the user's files.
	 */
	public function path(string $userId): string {
		return $this->configuredPath($userId) ?? $this->defaultPath($userId);
	}

	/**
	 * The folder the user explicitly configured, or null when none was stored.
	 *
	 * The settings JSON wins whenever it holds a string `notesPath`, empty included. The standalone value predates it
	 * and is read only when the JSON has none; there an empty value cannot be told from a missing one, so it counts as
	 * absent.
	 *
	 * @param string $userId Authenticated user.
	 * @return string|null Stored path ('' for the root of the user's files), null when nothing was configured.
	 */
	private function configuredPath(string $userId): ?string {
		$settings = json_decode((string)$this->config->getUserValue($userId, self::NOTES_APP, 'settings', ''), true);
		if (is_array($settings) && is_string($settings['notesPath'] ?? null)) {
			return $settings['notesPath'];
		}
		$standalone = trim((string)$this->config->getUserValue($userId, self::NOTES_APP, 'notesPath', ''));
		return $standalone !== '' ? $standalone : null;
	}

	/**
	 * The default folder as the Notes app picks it: the configured default name when it exists in the user's files,
	 * otherwise its translation in the user's language. With neither present, the translated name is what the app
	 * would create, so a creation lands where the app will look for it. A literal `Notes` folder made by earlier MCP
	 * versions keeps being found, because the untranslated default is checked first.
	 *
	 * @param string $userId Authenticated user.
	 * @return string Default folder name.
	 */
	private function defaultPath(string $userId): string {
		$default = trim((string)$this->config->getAppValue(self::NOTES_APP, 'defaultFolder', self::DEFAULT_FOLDER));
		$default = $default !== '' ? $default : self::DEFAULT_FOLDER;
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if ($userFolder->nodeExists(ltrim($default, '/'))) {
			return $default;
		}
		$translated = $this->translate($userId, $default);
		return $translated !== '' ? $translated : $default;
	}

	/**
	 * Translates the default folder name with the Notes app's own catalogue, in the user's language.
	 *
	 * The first language of the account that the Notes app ships (forced language, account language, server default)
	 * wins, like the app's translator in the web interface. Any failure keeps the untranslated name.
	 *
	 * @param string $userId Authenticated user.
	 * @param string $text Default folder name.
	 * @return string Translated name, or '' when no translation is available.
	 */
	private function translate(string $userId, string $text): string {
		if ($this->l10n === null) {
			return '';
		}
		try {
			$user = $this->users?->get($userId);
			foreach ($this->l10n->getLanguageIterator($user) as $lang) {
				if ($lang === 'en' || $this->l10n->languageExists(self::NOTES_APP, $lang)) {
					return trim((string)$this->l10n->get(self::NOTES_APP, $lang)->t($text));
				}
			}
		} catch (\Throwable) {
			// The untranslated default is still a valid answer.
		}
		return '';
	}
}
