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
use OCP\Files\Node;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCA\Mcp\Platform\FileAccess\SharePermission;

/** Prepares a person or group share without writing. */
final class RecipientShareChange {
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
	 * Everything the plan shows and the write needs, checked in the order the person would want the reason: who, may
	 * I, which file, what the server allows, what I may give, until when. The node comes before any other refusal
	 * that depends on it, so a hidden node always answers "not found".
	 *
	 * @param Folder $userFolder the user's folder
	 * @param string $uid authenticated user
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ShareRecipient $recipient resolved person or group
	 * @return array{action:string, node:Node, path:string, folder:bool, recipient:ShareRecipient, existing:IShare|null, bits:int, expires:\DateTime|null, note:string|null, before:array<string, mixed>|null, after:array<string, mixed>, expiresSource:string, timezone:\DateTimeZone, warnings:list<string>, generatePassword:bool, passwordPlan:array{before:bool|null, after:string, required:bool}|null}
	 * @throws ArgumentValidationException|ToolFailure for any refusal
	 */
	public function prepare(Folder $userFolder, string $uid, array $arguments, ShareRecipient $recipient): array {
		if (isset($arguments['password'])) {
			throw new ArgumentValidationException('Invalid argument: password', 'password', Translator::t('only for a public link'));
		}
		$node = $this->access->ownNode($userFolder, $uid, (string)$arguments['path']);
		$this->access->assertFolderExposure($node);
		if ($recipient->kind === ShareRecipient::USER && $recipient->id === $uid) {
			throw new ToolFailure(SharingMessages::shareWithSelf());
		}
		$this->rules->assertServerAllows($uid, $recipient);
		$folder = NodeAccess::isFolder($node);
		$level = (string)($arguments['permission'] ?? SharePermission::VIEW);
		$bits = SharePermission::bits($level, $folder);
		if (!$node->isShareable()) {
			throw new ToolFailure(SharingMessages::shareNodeNotShareable());
		}
		if (($bits & ~(int)$node->getPermissions()) !== 0) {
			throw new ToolFailure(SharingMessages::shareAboveOwnPermissions());
		}
		$zone = $this->zones->forUser($uid);
		$expires = isset($arguments['expires']) ? $this->expiry->expiry((string)$arguments['expires'], $zone) : null;
		$note = isset($arguments['note']) ? (string)$arguments['note'] : null;
		$existing = $this->access->findExisting($uid, $node, $recipient);

		$before = $existing === null ? null : [
			'permission' => SharePermission::classify((int)$existing->getPermissions(), $folder),
			'reshare' => SharePermission::reshare((int)$existing->getPermissions()),
			'expires' => $existing->getExpirationDate()?->format('Y-m-d'),
			'note' => (string)$existing->getNote(),
		];
		[$afterExpires, $source] = match (true) {
			$expires !== null => [$expires->format('Y-m-d'), 'requested'],
			$before !== null => [$before['expires'], $before['expires'] === null ? 'none' : 'kept'],
			$this->shareManager->shareApiInternalDefaultExpireDate() => [$this->expiry->today($zone)
				->modify('+' . $this->shareManager->shareApiInternalDefaultExpireDays() . ' days')->format('Y-m-d'), 'default'],
			default => [null, 'none'],
		};
		$after = [
			'permission' => $level,
			'reshare' => false,
			'expires' => $afterExpires,
			'note' => $note ?? $before['note'] ?? null,
		];
		$unchanged = $existing !== null
			&& (int)$existing->getPermissions() === $bits
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
			'warnings' => $this->warnings($recipient, $folder, $level),
			'generatePassword' => false,
			'passwordPlan' => null,
		];
	}

	/**
	 * @param ShareRecipient $recipient person or group
	 * @param bool $folder whether the node is a folder
	 * @param string $level view or edit
	 * @return list<string> what the person must know before saying yes
	 */
	private function warnings(ShareRecipient $recipient, bool $folder, string $level): array {
		$warnings = [];
		if ($recipient->kind === ShareRecipient::GROUP) {
			$warnings[] = SharingMessages::shareGroupWarning($recipient->displayName);
		}
		if ($folder && $level === SharePermission::EDIT) {
			$warnings[] = SharingMessages::shareFolderEditWarning($recipient->displayName);
		}
		return $warnings;
	}
}
