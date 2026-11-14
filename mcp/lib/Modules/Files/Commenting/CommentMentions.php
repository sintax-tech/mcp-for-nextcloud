<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Commenting;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Presentation\CommentingMessages;
use OCA\Mcp\Platform\Sharing\ShareableAccount;

/**
 * The mentions a new comment will carry, read exactly as Nextcloud will read them, and who they will notify.
 *
 * {@see self::scan()} is `OC\Comments\Comment::getMentions()` with markdown support (lib/private/Comments/Comment.php
 * :208-261, the same in v34.0.4 and v35.0.1). The comments app notifies the `user` mentions of existing accounts other
 * than the author (apps/comments/lib/Notification/Listener.php, evaluate and extractMentions). This first slice accepts
 * only a `user` mention of the author or of an account the people search returns to the author
 * ({@see ShareableAccount}: enumeration and group restrictions apply); any other kind (guest, email, group, team,
 * federated) is refused by its syntax, and an account the search does not return is refused with one message whether
 * it exists or not. So no mention is silently dropped, none reaches an account the author could not find, and the
 * refusal reveals nothing about hidden accounts.
 */
final class CommentMentions {
	/** @param ShareableAccount $accounts the accounts the author may name, as the people search returns them */
	public function __construct(private ShareableAccount $accounts) {
	}

	/**
	 * @param string $message exact comment text
	 * @param string $author the actor, never notified of their own mention
	 * @return list<array{id:string,display_name:string}> the accounts the comment will notify
	 * @throws ToolFailure when a mention is not of one account, or of an account the people search does not return
	 */
	public function notified(string $message, string $author): array {
		$notified = [];
		foreach (self::scan($message) as $mention) {
			if ($mention['type'] !== 'user') {
				throw new ToolFailure(CommentingMessages::unsupportedMention($mention['raw']));
			}
			if ($mention['id'] === $author) {
				continue;
			}
			$name = $this->accounts->displayName($mention['id']);
			if ($name === null) {
				throw new ToolFailure(CommentingMessages::unavailableMention($mention['raw']));
			}
			$notified[] = ['id' => $mention['id'], 'display_name' => $name];
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
