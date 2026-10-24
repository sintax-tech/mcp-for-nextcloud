<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

/** Workflow guidance that describes configured backups without promising an unconditional copy. */
final class CheckoutPrompt {
	/**
	 * @param bool $create whether creation tools can be mentioned
	 * @param list<string> $createLines existing creation workflow shared by the catalog
	 * @return string guidance for the tool caller
	 */
	public static function text(bool $create, array $createLines): string {
		$lines = [
			'To edit a Nextcloud file without passing its content through the model, use the checkout workflow:',
			'',
			'1. Call files_checkout with the path. It returns download_url, upload_url, the current ETag, and expires_at.',
			'2. Download the file using your shell: curl -sS -o /tmp/file "$DOWNLOAD_URL".',
			'   The link is valid for a few minutes and single-use: if it fails, run files_checkout again.',
			'3. Edit /tmp/file with your local tools. The user sees the diff; do not write anything outside the file.',
			'4. Return the file with curl -sS -T /tmp/file -X PUT -H "Content-Type: application/octet-stream" "$UPLOAD_URL".',
			'   The server validates the token and ETag, applies the administrator MCP backup setting, saves the file, and returns the new ETag.',
			'   If the file changed in the meantime, the response is a conflict (409) and nothing was written: re-read and repeat.',
			'',
			'If you do not have a shell to download and upload the file, do not use checkout: apply the change with',
			'files_replace (replaces a unique snippet and returns the diff) or with files_edit (full content).',
			'files_edit and files_replace return the unified diff of before and after, truncated at 20,000 characters.',
			'',
			'Shared scope: any response with "requiresConfirmation": true means the file is outside your personal',
			'folder (shared, group folder, or external storage). In that case, DO NOT repeat the call immediately:',
			'show the received message to the user and ask if they wish to proceed. Then, confirm with confirm_shared: true.',
			'Without this confirmation, nothing is written.',
			'',
			'Upload note: checkout links are single-use and already consumed once the response arrives.',
			'If the upload returns a confirmation prompt or a conflict, run files_checkout again — passing',
			'confirm_shared: true if the user has already confirmed — instead of retrying the same link.',
			'',
			'To revert, use files_versions_list and files_version_restore; restoring follows the same administrator MCP backup setting.',
		];
		if ($create) {
			$lines[] = '';
			$lines[] = 'A NEW file is not a checkout: files_checkout only replaces a file that already exists.';
			$lines = array_merge($lines, $createLines);
		}
		return implode("\n", $lines);
	}
}
