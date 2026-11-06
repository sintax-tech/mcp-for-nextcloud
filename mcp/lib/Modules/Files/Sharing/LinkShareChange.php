<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Modules\Files\Presentation\SharingMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Share\IManager as IShareManager;
use OCA\Mcp\Platform\FileAccess\SharePermission;

/** Prepares a public link without generating a password or writing. */
final class LinkShareChange {
	/**
	 * Constructs the sharing collaborator.
	 *
	 * @param ShareAccess $access Own and visible node admission.
	 * @param ShareServerRules $rules Administrator sharing restrictions.
	 * @param ShareExpiry $expiry User-zone date checks.
	 * @param IShareManager $shareManager Public sharing API.
	 * @param UserTimezone $zones User timezone resolver.
	 */
	public function __construct(
		private ShareAccess $access,
		private ShareServerRules $rules,
		private ShareExpiry $expiry,
		private IShareManager $shareManager,
		private UserTimezone $zones,
	) {}

	/**
	 * The change of a public link (decision 7, invariants 2 and 4), in the shape {@see RecipientShareChange::prepare()} returns.
	 *
	 * One link per file: an existing link of the user is updated. Read only: `edit` is an argument error, a new link
	 * gets READ, and an existing one keeps its bits while they already read as view (the SHARE bit the core adds for
	 * outgoing federation is neither added nor taken), otherwise it comes back to READ. A password is generated on the
	 * confirmed call when asked (`password: true`, which also replaces the current one) or when the administrator
	 * enforces one and the link has none; otherwise the current password stays and is never shown.
	 *
	 * @param Folder $userFolder the user's folder
	 * @param string $uid authenticated user, already granted `link`
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ShareRecipient $recipient the `link` recipient
	 * @return array<string, mixed> the prepared link change, before the facade appends requested arguments
	 * @throws ArgumentValidationException for `edit` or an unusable date
	 * @throws ToolFailure for every other refusal, with a translated reason
	 */
	public function prepare(Folder $userFolder, string $uid, array $arguments, ShareRecipient $recipient): array {
		$node = $this->access->ownNode($userFolder, $uid, (string)$arguments['path']);
		$this->access->assertFolderExposure($node);
		$this->rules->assertLinksAllowed($uid);
		$level = (string)($arguments['permission'] ?? SharePermission::VIEW);
		if ($level !== SharePermission::VIEW) {
			throw new ArgumentValidationException('Invalid argument: permission', 'permission', Translator::t('only view for a public link'));
		}
		if (!$node->isShareable()) {
			throw new ToolFailure(SharingMessages::shareNodeNotShareable());
		}
		$folder = NodeAccess::isFolder($node);
		$existing = $this->access->findExisting($uid, $node, $recipient);
		$current = $existing === null ? null : (int)$existing->getPermissions();
		$bits = $current !== null && SharePermission::classify($current, $folder) === SharePermission::VIEW
			? $current : SharePermission::bits(SharePermission::VIEW, $folder);

		$zone = $this->zones->forUser($uid);
		$expires = isset($arguments['expires']) ? $this->expiry->expiry((string)$arguments['expires'], $zone, true) : null;
		$note = isset($arguments['note']) ? (string)$arguments['note'] : null;
		$hadPassword = $existing === null ? null : ShareAccess::hasPassword($existing);
		$required = $this->shareManager->shareApiLinkEnforcePassword();
		$generate = ($arguments['password'] ?? false) === true || ($required && $hadPassword !== true);

		$before = $existing === null ? null : [
			'permission' => SharePermission::classify($current, $folder),
			'reshare' => SharePermission::reshare($current),
			'expires' => $existing->getExpirationDate()?->format('Y-m-d'),
			'note' => (string)$existing->getNote(),
		];
		$enforced = $this->shareManager->shareApiLinkDefaultExpireDateEnforced();
		[$afterExpires, $source] = match (true) {
			$expires !== null => [$expires->format('Y-m-d'), 'requested'],
			$before !== null => [$before['expires'], $before['expires'] === null ? 'none' : 'kept'],
			$this->shareManager->shareApiLinkDefaultExpireDate() || $enforced => [$this->expiry->today($zone)
				->modify('+' . $this->shareManager->shareApiLinkDefaultExpireDays() . ' days')->format('Y-m-d'), 'default'],
			default => [null, 'none'],
		};
		if ($existing === null && $expires === null && $enforced && !$this->shareManager->shareApiLinkDefaultExpireDate()) {
			// The core refuses a new link without a date when the maximum is enforced and no default fills it in.
			$expires = new \DateTime($afterExpires . ' ' . ShareExpiry::HOUR, $zone);
		}
		$after = [
			'permission' => SharePermission::VIEW,
			'reshare' => SharePermission::reshare($bits),
			'expires' => $afterExpires,
			'note' => $note ?? $before['note'] ?? null,
		];
		$unchanged = $existing !== null && !$generate
			&& $current === $bits
			&& $afterExpires === $before['expires']
			&& ($note === null || $note === $before['note']);

		return [
			'action' => $existing === null ? ShareWriter::CREATE : ($unchanged ? ShareWriter::NONE : ShareWriter::UPDATE),
			'node' => $node,
			'path' => PathGuard::normalize((string)$arguments['path']),
			'folder' => $folder,
			'recipient' => $recipient,
			'existing' => $existing,
			'bits' => $bits,
			'expires' => $expires,
			'note' => $note,
			'before' => $before,
			'after' => $after,
			'expiresSource' => $source,
			'timezone' => $zone,
			'warnings' => [SharingMessages::linkPublicWarning()],
			'generatePassword' => $generate,
			'passwordPlan' => [
				'before' => $hadPassword,
				'after' => $generate ? 'new' : ($hadPassword === true ? 'kept' : 'none'),
				'required' => $required,
			],
		];
	}
}
