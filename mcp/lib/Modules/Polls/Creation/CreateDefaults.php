<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Creation;

/** The pinned persistence prediction and the public observation are deliberately distinct. */
final class CreateDefaults {
	/** Version of the complete schema/setter/accessor contract, bound into every plan. */
	public const MECHANISM = 'polls-create-persistence-readback-v1';
	/**
	 * Returns source-pinned expected fields (not a claim about a live installation schema).
	 * @param bool $nine native timezone surface
	 * @return array<string,mixed> announced persisted defaults
	 */
	public static function persisted(bool $nine): array {
		return ['access'=>'private','votingVariant'=>'simple','showResults'=>'always','description'=>null,
			'allowMaybe'=>1,'allowComment'=>1,'hideBookedUp'=>1,'useNo'=>1,'allowProposals'=>'disallow',
			'proposalsExpire'=>0,'optionLimit'=>0,'expire'=>0,'anonymous'=>0,'voteLimit'=>0,'deleted'=>0,
			'adminAccess'=>0,'miscSettings'=>null] + ($nine ? ['timezoneName'=>null] : []);
	}
	/**
	 * Public accessors normalize description and timezone; NULL cannot always be distinguished from empty string.
	 * @param bool $nine native timezone surface
	 * @return array<string,mixed> observable field values
	 */
	public static function observable(bool $nine): array {
		$values = self::persisted($nine); $values['description'] = '';
		return $values;
	}
	/**
	 * Binds source contract and exact observation rules, without introspecting live DB/schema.
	 * @param bool $nine native timezone surface
	 * @return array<string,mixed> stable non-secret mechanism fingerprint
	 */
	public static function fingerprint(bool $nine): array {
		return ['mechanism'=>self::MECHANISM,'persisted'=>self::persisted($nine),'observable'=>self::observable($nine),
			'accessors'=>'public Entity::__call/getter; description ?? empty; timezone empty -> null; misc raw',
			'setter'=>'equal argument returns before updatedFields; QBMapper writes updatedFields only',
			'layout'=>$nine?'add(type,title,null,simple); Option.setPoll(0) first':'add(type,title,simple); Option.setPollId(0) first'];
	}
	/**
	 * Reads only the announced safe fields from a NEW authorized native readback, never jsonSerialize.
	 * @param object $poll native poll entity
	 * @param bool $nine native timezone surface
	 * @return array<string,mixed> actual observable values, with original types
	 */
	public static function read(object $poll, bool $nine): array {
		return ['access'=>$poll->getAccess(),'votingVariant'=>$poll->getVotingVariant(),'showResults'=>$poll->getShowResults(),
			'description'=>$poll->getDescription(),'allowMaybe'=>$poll->getAllowMaybe(),'allowComment'=>$poll->getAllowComment(),
			'hideBookedUp'=>$poll->getHideBookedUp(),'useNo'=>$poll->getUseNo(),'allowProposals'=>$poll->getAllowProposals(),
			'proposalsExpire'=>$poll->getProposalsExpire(),'optionLimit'=>$poll->getOptionLimit(),'expire'=>$poll->getExpire(),
			'anonymous'=>$poll->getAnonymous(),'voteLimit'=>$poll->getVoteLimit(),'deleted'=>$poll->getDeleted(),
			'adminAccess'=>$poll->getAdminAccess(),'miscSettings'=>$poll->getMiscSettings()] + ($nine ? ['timezoneName'=>$poll->getTimezoneName()] : []);
	}
}
