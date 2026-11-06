<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Attachments;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Modules\Files\Sharing\SharePermission;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PlanText;
use OCP\Share\IShare;

/**
 * Public warning and bounded presentation of an already authorized complete link snapshot.
 */
final class CardLinkPlan {
	/**
	 * Receive only the existing instance-secret HMAC mechanism.
	 *
	 * @param PlanState $plans Existing plan fingerprints; no persisted approvals or new cryptography.
	 * @return void
	 */
	public function __construct(private PlanState $plans) {
	}

	/**
	 * Present authorized fields and sign the full internal snapshot including structural audience and policies.
	 *
	 * @param array<string, mixed> $state Complete fresh preparation result.
	 * @param string $uid Authenticated actor whose exact identity the HMAC binds.
	 * @return array<string, mixed> Executable preview without raw ACL or policy settings.
	 */
	public function of(array $state, string $uid): array {
		$snapshot = $state['snapshot'];
		$board = $snapshot['board'] + ['shared' => $snapshot['board']['owner'] !== $uid];
		$shared = $board['shared'] ? [$board] : [];
		if ($snapshot['fileAccess']['scope'] !== NodeAccessInfo::PERSONAL) {
			$shared[] = $snapshot['fileAccess'] + ['resource' => $snapshot['file']['path']];
		}
		return ['action' => \OCA\Mcp\Modules\Deck\Recipients\LinkRecipientContext::OPERATION, 'card' => $snapshot['origin'], 'board' => $board,
			'file' => $snapshot['file'], 'reshare' => $snapshot['reshare'], 'existingShares' => $snapshot['shares'],
			'existingSharesScope' => \OCA\Mcp\Modules\Deck\Attachments\CardLinkMessages::shareScope(), 'recipients' => $state['recipients'], 'permissions' => \OCP\Constants::PERMISSION_READ,
			'shared' => $shared, 'recoverable' => true,
			'message' => \OCA\Mcp\Modules\Deck\Attachments\CardLinkMessages::warning(),
			PlanState::ARGUMENT => $this->plans->of(\OCA\Mcp\Modules\Deck\Recipients\LinkRecipientContext::OPERATION, $uid, $snapshot)];
	}

	/**
	 * Render visible fields and the entire mandatory warning as inert Markdown.
	 *
	 * @param array<string, mixed> $plan Authorized public link preview.
	 * @return string Localized plan body.
	 */
	public static function render(array $plan): string {
		$file = $plan['file'];
		$shares = array_map(static function (array $share): string {
			$type = match ($share['type']) {
				IShare::TYPE_USER => Translator::t('person'),
				IShare::TYPE_GROUP => Translator::t('group'),
				default => Translator::t('public link'),
			};
			$permission = match (SharePermission::classify($share['permissions'], false)) {
				SharePermission::VIEW => Translator::t('Read only'),
				SharePermission::EDIT => Translator::t('Can edit'),
				default => Translator::t('Custom permissions'),
			};
			if (SharePermission::reshare($share['permissions'])) {
				$permission .= ', ' . Translator::t('can share onward');
			}
			return PlanText::inline($share['id']) . ' (' . $type . ', '
				. PlanText::inline($share['recipient'] ?? Translator::t('public link')) . ', ' . $permission . ')';
		}, $plan['existingShares'] ?? []);
		$names = array_map(static fn (array $member): string => PlanText::inline($member['name']), $plan['recipients']['names']);
		return Translator::t('Link %s to Deck card %s.', [PlanText::em($file['name']), PlanText::em($plan['card']['title'])])
			. "\n\n" . Translator::t('File: %s; owner: %s; size: %s bytes.',
				[PlanText::inline($file['path']), PlanText::inline($file['owner']), (string)$file['size']])
			. "\n\n" . Translator::t('Current recipients: %s. Names shown: %s.', [(string)$plan['recipients']['total'], implode(', ', $names)])
			. "\n\n" . Translator::t('Reshare: %s.', [$plan['reshare'] ?? false ? Translator::t('yes') : Translator::t('no')])
			. "\n\n" . Translator::t('Existing shares created by you (ID, type, recipient, permissions): %s.', [implode(', ', $shares)])
			. "\n\n" . \OCA\Mcp\Modules\Deck\Attachments\CardLinkMessages::shareScope()
			. "\n\n" . \OCA\Mcp\Modules\Deck\Attachments\CardLinkMessages::warning();
	}
}
