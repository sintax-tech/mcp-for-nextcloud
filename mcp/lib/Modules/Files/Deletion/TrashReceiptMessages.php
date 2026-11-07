<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Deletion;

use OCA\Mcp\L10n\Translator;

/** Receipt texts for what was observed in the trash bin after deletion; none of them promises future recovery. */
final class TrashReceiptMessages {
	/** Plan field value for each destination of \OCA\Mcp\Modules\Files\Integration\TrashReceiptVerifier. */
	public const PLAN = [\OCA\Mcp\Modules\Files\Integration\TrashReceiptVerifier::OWN => 'own_trash', \OCA\Mcp\Modules\Files\Integration\TrashReceiptVerifier::OTHER => 'owner_trash_not_checked',
		\OCA\Mcp\Modules\Files\Integration\TrashReceiptVerifier::UNKNOWN => 'not_confirmed'];

	/**
	 * Plan note for a destination, with a leading space, or an empty string for the actor's own trash bin.
	 *
	 * @param string|null $destination Destination from \OCA\Mcp\Modules\Files\Integration\TrashReceiptVerifier, or null without a verifier.
	 * @return string Text appended to the plan message.
	 */
	public static function planNote(?string $destination): string {
		return match ($destination) {
			\OCA\Mcp\Modules\Files\Integration\TrashReceiptVerifier::OTHER => ' ' . self::ownerTrash(),
			\OCA\Mcp\Modules\Files\Integration\TrashReceiptVerifier::UNKNOWN => ' ' . self::unknownTrash(),
			default => '',
		};
	}

	/** @return string File seen in the actor's own trash bin at the moment of the check. */
	public static function observedFile(): string {
		return Translator::t('The file is no longer at its original path and was confirmed in your trash bin at the time of the check. No recovery copy was created by MCP.');
	}

	/** @return string Folder root seen in the actor's own trash bin, without vouching for every descendant. */
	public static function observedFolder(): string {
		return Translator::t('The folder is no longer at its original path and was confirmed in your trash bin at the time of the check. Only the folder itself was checked, not each item inside it. No recovery copy was created by MCP.');
	}

	/** @return string Time-limit warning attached to every observed receipt. */
	public static function expiry(): string {
		return Translator::t('The trash bin keeps items only for a limited time and can remove them automatically, for example when the configured time limit ends or the trash bin runs out of space. Recovery is not guaranteed.');
	}

	/** @return string Neutral plan note when the owner cannot be read; it names nobody and promises no check. */
	public static function unknownTrash(): string {
		return Translator::t('The trash bin destination of this item could not be determined, so it will not be confirmed after deletion.');
	}

	/** @return string Received-share limitation, stated in the plan and in the receipt. */
	public static function ownerTrash(): string {
		return Translator::t('This item belongs to another person who shared it with you, so Nextcloud sends it to the trash bin of the owner. MCP does not check the trash bin of another person, so the destination is not confirmed.');
	}
}
