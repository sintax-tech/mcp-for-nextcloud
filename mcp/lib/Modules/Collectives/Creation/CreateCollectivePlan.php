<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\TeamsAdmission;
use OCA\Mcp\Modules\Collectives\Naming\CollectiveName;
use OCA\Mcp\Modules\Collectives\Naming\NameConflict;
use OCA\Mcp\Modules\Collectives\Presentation\FoundingMessages;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;
use OCP\App\IAppManager;
use OCP\IDBConnection;

/**
 * The plan of `collectives_create_collective`, read now and only read: the admission (grant, app, Guests, the actor's
 * Teams identity already recorded, not a guest account), the name as Collectives and Teams will store it, the emoji,
 * and the collectives and teams the actor sees, of which none may have that name. It lists everything that is
 * created, and what may happen besides (the adoption race of decision D6, a display-name suffix, a team folder on
 * Teams 35). The confirmed call rebuilds it and creates only when it is still the approved one.
 */
class CreateCollectivePlan {
	/** Tool the plan belongs to. */
	public const TOOL = 'collectives_create_collective';
	/** First Teams major that may add a team folder to a new team (`TeamFolderLifecycleListener`, Circles 35). */
	private const TEAM_FOLDERS = 35;

	/**
	 * @param TeamsAdmission $admission what the call proves first
	 * @param NameConflict $conflicts the names the actor sees, read now
	 * @param PlanConfirmation $confirmation plan states
	 * @param IDBConnection $db whether the database keeps four-byte characters
	 * @param IAppManager $apps the Teams release, for the team folder notice
	 */
	public function __construct(
		private TeamsAdmission $admission,
		private NameConflict $conflicts,
		private PlanConfirmation $confirmation,
		private IDBConnection $db,
		private IAppManager $apps,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_create_collective arguments
	 * @return array{plan:array<string, mixed>, name:string, emoji:?string, snapshot:array{collective_ids:list<int>, team_ids:list<string>}}
	 *     the signed plan, the name and emoji to send, and what the actor sees now
	 * @throws ToolFailure when the call is not admitted, the name or emoji is refused, or the name is taken
	 */
	public function build(string $uid, array $arguments): array {
		$this->admission->admit($uid, TeamsAdmission::CREATE_COLLECTIVE);
		$given = (string)$arguments['name'];
		$name = CollectiveName::normalize($given, $this->db->supports4ByteText());
		$emoji = CollectiveName::emoji(isset($arguments['emoji']) ? (string)$arguments['emoji'] : null);
		$seen = $this->conflicts->snapshot($uid, $name);
		if ($seen['taken']) {
			throw new ToolFailure(FoundingMessages::nameTaken($name));
		}
		$teams = (string)$this->apps->getAppVersion('circles');
		$notices = array_values(array_filter([
			$name !== $given ? FoundingMessages::storedAs($name) : null,
			FoundingMessages::createsTeam(),
			FoundingMessages::createsFolder(),
			FoundingMessages::nobodyInvited(),
			FoundingMessages::nameMaySuffix(),
			(int)explode('.', $teams)[0] >= self::TEAM_FOLDERS ? FoundingMessages::teamFolderPossible() : null,
			FoundingMessages::adoptionPossible($name),
			FoundingMessages::adoptionUnknowable(),
		]));
		$plan = ['action' => self::TOOL, 'name' => $name, 'requested_name' => $given, 'emoji' => $emoji,
			'creates' => ['team', 'collective', 'folder', 'landing_page'], 'owner' => $uid, 'other_members' => [], 'notices' => $notices];
		return ['plan' => $this->confirmation->sign(self::TOOL, $uid, $plan, ['name' => $name, 'emoji' => $emoji]),
			'name' => $name, 'emoji' => $emoji, 'snapshot' => ['collective_ids' => $seen['collective_ids'], 'team_ids' => $seen['team_ids']]];
	}
}
