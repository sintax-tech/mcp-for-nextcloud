<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Commenting;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Presentation\CommentingMessages;
use OCP\IUserManager;

/**
 * The mentions a new comment will carry, read exactly as Nextcloud will read them, and who they will notify.
 *
 * {@see self::scan()} is `OC\Comments\Comment::getMentions()` with markdown support (lib/private/Comments/Comment.php
 * :208-261, the same in v34.0.4 and v35.0.1). The comments app notifies only the `user` mentions of existing accounts
 * other than the author (apps/comments/lib/Notification/Listener.php, evaluate and extractMentions). This first slice
 * keeps exactly those: a mention of any other kind (guest, email, group, team, federated) or of an account that does
 * not exist is refused before anything is written, so no mention is ever silently dropped or sent somewhere unseen.
 */
final class CommentMentions {
	/** @param IUserManager $users the accounts the native listener checks with userExists */
	public function __construct(private IUserManager $users) {
	}

	/**
	 * @param string $message exact comment text
	 * @param string $author the actor, never notified of their own mention
	 * @return list<array{id:string,display_name:string}> the accounts the comment will notify
	 * @throws ToolFailure when a mention is not of a single existing account
	 */
	public function notified(string $message, string $author): array {
		$notified = [];
		foreach (self::scan($message) as $mention) {
			if ($mention['type'] !== 'user') {
				throw new ToolFailure(CommentingMessages::unsupportedMention($mention['raw']));
			}
			if (!$this->users->userExists($mention['id'])) {
				throw new ToolFailure(CommentingMessages::unknownMention($mention['raw']));
			}
			if ($mention['id'] !== $author) {
				$notified[] = ['id' => $mention['id'], 'display_name' => $this->users->getDisplayName($mention['id']) ?? $mention['id']];
			}
		}
		return $notified;
	}

	/**
	 * @param string $message exact comment text
	 * @return list<array{type:string,id:string,raw:string}> the native mentions, longest first, with the text that made them
	 */
	public static function scan(string $message): array {
		// Strip fenced code blocks and inline code so mentions inside them are ignored (Comment.php:210-214).
		$message = preg_replace('/^```.*?(^```|\z)|^~~~.*?(^~~~|\z)/sm', '', $message);
		$message = preg_replace('/`[^`\n]*`/', '', (string)$message);
		$ok = preg_match_all("/\B(?<![^a-z0-9_\-@\.\'\s])@(\"(guest|email)\/[a-f0-9]+\"|\"(?:federated_)?(?:group|team|user){1}\/[a-z0-9_\-@\.\' \/:]+\"|\"[a-z0-9_\-@\.\' ]+\"|[a-z0-9_\-@\.\']+)/i", (string)$message, $mentions);
		if (!$ok || !isset($mentions[0])) {
			return [];
		}
		$raw = array_unique($mentions[0]);
		usort($raw, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
		$result = [];
		foreach ($raw as $mention) {
			$clean = trim(substr($mention, 1), '"');
			[$type, $id] = ['user', $clean];
			foreach (['guest/' => 'guest', 'email/' => 'email', 'federated_group/' => 'federated_group', 'group/' => 'group',
				'federated_team/' => 'federated_team', 'team/' => 'team', 'federated_user/' => 'federated_user'] as $prefix => $kind) {
				if (str_starts_with($clean, $prefix)) {
					[$type, $id] = [$kind, $kind === 'guest' ? $clean : substr($clean, strlen($prefix))];
					break;
				}
			}
			$result[] = ['type' => $type, 'id' => $id, 'raw' => $mention];
		}
		return $result;
	}
}
