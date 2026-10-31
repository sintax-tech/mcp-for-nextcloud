<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\Mcp\Confirmation\PlanState;

/**
 * Ephemeral complete recipient portrait; hashes reuse PlanState and do not attest human consent or atomicity.
 */
final readonly class RecipientSnapshot {
	/**
	 * Maximum current recipient names included in the eventual link warning.
	 */
	public const MAX_NAMES = 10;
	/**
	 * Maximum Unicode characters of each presented display name.
	 */
	public const MAX_NAME_CHARS = 80;

	/**
	 * Complete current people count, not ACL entries or new readers.
	 *
	 * @var int
	 */
	public int $total;
	/**
	 * Complete binary UID-sorted internal identities.
	 *
	 * @var list<string>
	 */
	public array $uids;
	/**
	 * Bounded UID-ordered current presentation.
	 *
	 * @var list<array{uid:string,name:string}>
	 */
	public array $names;
	/**
	 * Whether some authorized names are omitted from the limited preview.
	 *
	 * @var bool
	 */
	public bool $truncated;
	/**
	 * HMAC of the exact context and current canonical UID audience.
	 *
	 * @var string
	 */
	public string $recipientHash;
	/**
	 * HMAC binding the audience, preview and future-audience ACL structure.
	 *
	 * @var string
	 */
	public string $planHash;
	/**
	 * Canonical deduplicated internal ACL set; never expose raw ACLs to clients.
	 *
	 * @var list<array{type:int,participant:string,edit:bool,share:bool,manage:bool}>
	 */
	public array $acls;

	/**
	 * Fingerprint a UID-sorted complete expansion and its limited presentation using the existing HMAC.
	 *
	 * @param LinkRecipientContext $context Authenticated target binding.
	 * @param list<array{uid:string,name:string}> $members Complete UID-sorted and deduplicated identities.
	 * @param list<array{type:int,participant:string,edit:bool,share:bool,manage:bool}> $acls Every inspected ACL row.
	 * @param bool $hasGroupAcl Whether any group ACL exists, even when the group is empty.
	 * @param bool $hasCircleAcl Whether any circle ACL exists, even when the circle is empty.
	 * @param PlanState $plans Existing instance-secret fingerprint provider; no new cryptographic scheme.
	 * @return void
	 */
	public function __construct(public LinkRecipientContext $context, array $members, array $acls,
		public bool $hasGroupAcl, public bool $hasCircleAcl, PlanState $plans) {
		$this->acls = self::canonicalAcls($acls);
		$this->uids = array_column($members, 'uid');
		$this->total = count($this->uids);
		$this->names = array_map(static fn (array $member): array => ['uid' => $member['uid'],
			'name' => mb_substr($member['name'], 0, self::MAX_NAME_CHARS, 'UTF-8')], array_slice($members, 0, self::MAX_NAMES));
		$this->truncated = $this->total > count($this->names);
		$this->recipientHash = $plans->of(LinkRecipientContext::OPERATION, $context->actorUid,
			['context' => $context->state(), 'recipientUids' => $this->uids]);
		$this->planHash = $plans->of(LinkRecipientContext::OPERATION, $context->actorUid,
			['recipientHash' => $this->recipientHash, 'presentation' => $this->presentation(), 'acls' => $this->acls]);
	}

	/**
	 * Return only the bounded authorized presentation and the group/circle flags needed by DK-4b.
	 *
	 * @return array{total:int,names:list<array{uid:string,name:string}>,truncated:bool,hasGroupAcl:bool,hasCircleAcl:bool} No independent-share metadata.
	 */
	public function presentation(): array {
		return ['total' => $this->total, 'names' => $this->names, 'truncated' => $this->truncated,
			'hasGroupAcl' => $this->hasGroupAcl, 'hasCircleAcl' => $this->hasCircleAcl];
	}

	/**
	 * Deduplicate and order the full ACL structure independently of native row order or database IDs.
	 *
	 * @param list<array{type:int,participant:string,edit:bool,share:bool,manage:bool}> $acls Complete authorized rows.
	 * @return list<array{type:int,participant:string,edit:bool,share:bool,manage:bool}> Internal canonical set.
	 */
	private static function canonicalAcls(array $acls): array {
		$unique = [];
		foreach ($acls as $acl) {
			$row = ['type' => $acl['type'], 'participant' => $acl['participant'], 'edit' => $acl['edit'],
				'share' => $acl['share'], 'manage' => $acl['manage']];
			$unique[json_encode($row, JSON_THROW_ON_ERROR)] = $row;
		}
		$rows = array_values($unique);
		usort($rows, static fn (array $a, array $b): int => ($a['type'] <=> $b['type'])
			?: strcmp($a['participant'], $b['participant']) ?: ($a['edit'] <=> $b['edit'])
			?: ($a['share'] <=> $b['share']) ?: ($a['manage'] <=> $b['manage']));
		return $rows;
	}
}
