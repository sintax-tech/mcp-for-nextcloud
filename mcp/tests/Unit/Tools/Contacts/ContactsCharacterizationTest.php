<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <jhonatan@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of the Contacts call paths, recorded before the module was split into catalog tools (RE-15 b0) and
 * compared byte for byte on every later commit, each run in a process of its own.
 *
 * Every scenario starts from a fresh {@see ContactsScene} and records, step by step, what the module answers to a plan
 * (preview) and to a call: the result, or the class, message and code of every exception that leaves the module (and
 * the plan a PlanChanged carries), then the CardDAV writes, the backups and the catalog searches that step caused, and
 * at the end the stored cards. Strings that are not valid UTF-8 are recorded as their bytes, so a raw name and the
 * U+FFFD of the shared-book notice stay apart. Random UIDs, the ETags derived from them and plan_state HMACs are
 * numbered by first appearance.
 *
 * Set MCP_CHARACTERIZE_WRITE=1 to record the fixture again; it must only ever be recorded on the commit before the split.
 */
final class ContactsCharacterizationTest extends TestCase {
	/** Fixture the recorded run is compared with. */
	private const FIXTURE = __DIR__ . '/../../../fixtures/contacts-characterization.json';

	private ContactsScene $scene;
	/** @var array<string, mixed> the last plan a preview returned */
	private array $plan = [];

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testTheCallPathsAnswerAsBeforeTheSplit(): void {
		$recorded = [];
		foreach ($this->scenarios() as $name => $steps) {
			$recorded[$name] = $this->scenario($steps);
		}
		$json = self::normalized(json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . "\n";
		if (getenv('MCP_CHARACTERIZE_WRITE') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}
		self::assertSame((string)file_get_contents(self::FIXTURE), $json);
	}

	/**
	 * @return array<string, list<array{0:string, 1:string, 2:array<string, mixed>|\Closure(): array<string, mixed>}|\Closure(): mixed>> steps per
	 *     scenario: [kind, tool, arguments], kind being preview, call or definitions; a callable prepares the scene before the next step
	 */
	private function scenarios(): array {
		$p = ContactsScene::PERSONAL; $t = ContactsScene::TEAM; $b = ContactsScene::BROKEN; $ro = ContactsScene::READ_ONLY;
		$sys = ContactAccess::systemPath('alice');
		$new = ['name' => 'Nova Pessoa', 'emails' => ['nova@example.invalid'], 'phones' => ['+55 11 92222-2222'], 'organization' => 'Firma'];
		$token = fn (array $arguments): \Closure => fn (): array => $arguments + ['plan_state' => $this->plan['plan_state'] ?? ''];
		$etag = fn (string $book, int $id, string $uri, bool $quoted): \Closure => fn (): array => ['addressbook' => $book, 'uri' => $uri,
			'name' => 'Maria Souza Lima', 'confirm' => true, 'etag' => $quoted ? $this->scene->contacts->cards[$id][$uri]['etag'] : trim($this->scene->contacts->cards[$id][$uri]['etag'], '"')];
		$off = fn (): array => $this->scene->flags = ['shareapi_allow_share_dialog_user_enumeration' => 'no', 'shareapi_restrict_user_enumeration_full_match' => 'no'];
		return [
			'definitions' => [['definitions', '', []]],
			'list' => [['call', 'contacts_list_addressbooks', []], $off, ['call', 'contacts_list_addressbooks', []]],
			'search' => [['call', 'contacts_search_contacts', []],
				['call', 'contacts_search_contacts', ['query' => 's']],
				['call', 'contacts_search_contacts', ['query' => 'Souza']],
				['call', 'contacts_search_contacts', ['query' => 'souza', 'limit' => 2, 'offset' => 1]],
				['call', 'contacts_search_contacts', ['query' => 'bob']],
				['call', 'contacts_search_contacts', ['query' => 'carla']],
				['call', 'contacts_search_contacts', ['addressbook' => $sys, 'query' => 'b']],
				['call', 'contacts_search_contacts', ['addressbook' => $sys, 'query' => 'Souza']],
				['call', 'contacts_search_contacts', ['addressbook' => $t]],
				['call', 'contacts_search_contacts', ['addressbook' => $p, 'query' => '91111']],
				['call', 'contacts_search_contacts', ['addressbook' => '/remote.php/dav/addressbooks/users/alice/nope/']],
				['call', 'contacts_search_contacts', ['addressbook' => '/remote.php/dav/addressbooks/users/alice/system/']],
				$off, ['call', 'contacts_search_contacts', ['addressbook' => $sys, 'query' => 'Souza']],
				['call', 'contacts_search_contacts', ['query' => 'Souza']]],
			'search exact matches' => [fn () => $this->scene->flags = ['shareapi_allow_share_dialog_user_enumeration' => 'no'],
				['call', 'contacts_search_contacts', ['query' => 'bob']],
				['call', 'contacts_search_contacts', ['query' => 'Carla Souza']],
				fn () => $this->scene->flags += ['shareapi_restrict_user_enumeration_full_match_email' => 'no', 'shareapi_restrict_user_enumeration_full_match_userid' => 'no'],
				['call', 'contacts_search_contacts', ['query' => 'bob@example.invalid']],
				['call', 'contacts_read_contact', ['addressbook' => $sys, 'uri' => 'Database:bob.vcf']],
				['call', 'contacts_read_contact', ['addressbook' => $sys, 'uri' => 'Database:alice.vcf']]],
			'search restricted' => [fn () => $this->scene->flags = ['shareapi_restrict_user_enumeration_to_group' => 'yes'],
				['call', 'contacts_search_contacts', ['addressbook' => $sys, 'query' => 'a']],
				['call', 'contacts_search_contacts', ['addressbook' => $sys, 'query' => 'Souza']],
				fn () => $this->scene->flags = ['shareapi_restrict_user_enumeration_to_phone' => 'yes'],
				['call', 'contacts_search_contacts', ['addressbook' => $sys, 'query' => 'Souza']],
				['call', 'contacts_search_contacts', ['addressbook' => $sys, 'query' => 'Alice']]],
			'read' => [['call', 'contacts_read_contact', ['addressbook' => $p, 'uri' => 'c1.vcf']],
				['call', 'contacts_read_contact', ['addressbook' => $t, 'uri' => 't1.vcf']],
				['call', 'contacts_read_contact', ['addressbook' => $p, 'uri' => '../team/t1.vcf']],
				['call', 'contacts_read_contact', ['addressbook' => $p, 'uri' => 'bad.vcf']],
				['call', 'contacts_read_contact', ['addressbook' => $p, 'uri' => 'missing.vcf']],
				['call', 'contacts_read_contact', ['addressbook' => $sys, 'uri' => 'Database:bob.vcf']],
				['call', 'contacts_read_contact', ['addressbook' => $sys, 'uri' => 'Guests:gus.vcf']],
				['call', 'contacts_read_contact', ['addressbook' => $sys, 'uri' => 'Database:gina.vcf']],
				['call', 'contacts_read_contact', ['addressbook' => $sys, 'uri' => 'bob.vcf']]],
			'create' => [['preview', 'contacts_create_contact', ['addressbook' => $p] + $new],
				['call', 'contacts_create_contact', ['addressbook' => $p] + $new],
				['call', 'contacts_create_contact', ['addressbook' => $p, 'confirm' => true] + $new],
				['call', 'contacts_create_contact', $token(['addressbook' => $p, 'confirm' => true, 'name' => 'Outra Pessoa'] + $new)],
				['call', 'contacts_create_contact', $token(['addressbook' => $p, 'confirm' => true] + $new)],
				['call', 'contacts_create_contact', $token(['addressbook' => $p, 'confirm' => true] + $new)],
				['call', 'contacts_create_contact', ['addressbook' => $sys, 'confirm' => true, 'plan_state' => str_repeat('a', 32) . '.' . str_repeat('b', 64)] + $new],
				['preview', 'contacts_create_contact', ['addressbook' => $ro] + $new],
				['preview', 'contacts_create_contact', ['addressbook' => '/remote.php/dav/addressbooks/users/alice/nope/'] + $new]],
			'create shared' => [['preview', 'contacts_create_contact', ['addressbook' => $t, 'confirm_shared' => true] + $new],
				['call', 'contacts_create_contact', ['addressbook' => $t, 'confirm' => true] + $new],
				['call', 'contacts_create_contact', $token(['addressbook' => $t, 'confirm' => true] + $new)],
				['call', 'contacts_create_contact', $token(['addressbook' => $t, 'confirm' => true, 'confirm_shared' => true] + $new)]],
			'shared notice with invalid UTF-8' => [['preview', 'contacts_create_contact', ['addressbook' => $b] + $new],
				['preview', 'contacts_edit_contact', ['addressbook' => $b, 'uri' => 'b1.vcf', 'title' => 'Chefe']],
				['preview', 'contacts_delete_contact', ['addressbook' => $b, 'uri' => 'b1.vcf']]],
			'edit' => [['call', 'contacts_edit_contact', ['addressbook' => '/remote.php/dav/addressbooks/users/alice/nope/', 'uri' => 'c1.vcf']],
				['preview', 'contacts_edit_contact', ['addressbook' => $p, 'uri' => 'c1.vcf', 'name' => 'Maria Souza Lima', 'emails' => []]],
				['call', 'contacts_edit_contact', ['addressbook' => $p, 'uri' => 'c1.vcf', 'name' => 'Maria Souza Lima']],
				['call', 'contacts_edit_contact', ['addressbook' => $p, 'uri' => 'c1.vcf', 'name' => 'Maria Souza Lima', 'confirm' => true, 'etag' => '"stale"']],
				['call', 'contacts_edit_contact', $etag($p, 1, 'c1.vcf', false)],
				['call', 'contacts_edit_contact', ['addressbook' => $p, 'uri' => 'c1.vcf', 'phones' => ['+55 11 93333-3333'], 'note' => 'Nota', 'confirm' => true]],
				['call', 'contacts_edit_contact', ['addressbook' => $sys, 'uri' => 'Database:bob.vcf', 'name' => 'X', 'confirm' => true]],
				['call', 'contacts_edit_contact', ['addressbook' => $ro, 'uri' => 'r1.vcf', 'name' => 'X', 'confirm' => true]],
				['call', 'contacts_edit_contact', ['addressbook' => $p, 'uri' => 'missing.vcf', 'name' => 'X', 'confirm' => true]]],
			'edit shared' => [['preview', 'contacts_edit_contact', ['addressbook' => $t, 'uri' => 't1.vcf', 'title' => 'Chefe', 'confirm_shared' => true]],
				['call', 'contacts_edit_contact', ['addressbook' => $t, 'uri' => 't1.vcf', 'title' => 'Chefe', 'confirm' => true]],
				['call', 'contacts_edit_contact', ['addressbook' => $t, 'uri' => 't1.vcf', 'title' => 'Chefe', 'confirm' => true, 'confirm_shared' => true]]],
			'delete' => [['preview', 'contacts_delete_contact', ['addressbook' => $p, 'uri' => 'c1.vcf']],
				['call', 'contacts_delete_contact', ['addressbook' => $p, 'uri' => 'c1.vcf']],
				['call', 'contacts_delete_contact', ['addressbook' => $p, 'uri' => 'c1.vcf', 'confirm' => true, 'etag' => '"stale"']],
				['call', 'contacts_delete_contact', $etag($p, 1, 'c1.vcf', false)],
				['call', 'contacts_delete_contact', ['addressbook' => $p, 'uri' => 'c2.vcf', 'confirm' => true]],
				['call', 'contacts_delete_contact', ['addressbook' => $sys, 'uri' => 'Database:bob.vcf', 'confirm' => true]],
				['call', 'contacts_delete_contact', ['addressbook' => $ro, 'uri' => 'r1.vcf', 'confirm' => true]]],
			'delete shared' => [['call', 'contacts_delete_contact', ['addressbook' => $t, 'uri' => 't1.vcf', 'confirm' => true]],
				['call', 'contacts_delete_contact', ['addressbook' => $t, 'uri' => 't1.vcf', 'confirm' => true, 'confirm_shared' => true]]],
			'delete when the backup fails' => [fn () => $this->scene->backupFails = true,
				['call', 'contacts_delete_contact', ['addressbook' => $p, 'uri' => 'c1.vcf', 'confirm' => true]]],
			'delete when the card changes during the backup' => [fn () => $this->scene->duringBackup = fn () => $this->scene->contacts->plant(1, 'c1.vcf',
				ContactsScene::card('c1', 'Maria Souza', 'NOTE:outro cliente')),
				['call', 'contacts_delete_contact', ['addressbook' => $p, 'uri' => 'c1.vcf', 'confirm' => true]]],
			'delete when only the ETag quotes change during the backup' => [fn () => $this->scene->duringBackup = function (): void {
				$this->scene->contacts->cards[1]['c1.vcf']['etag'] = trim($this->scene->contacts->cards[1]['c1.vcf']['etag'], '"');
			}, ['call', 'contacts_delete_contact', ['addressbook' => $p, 'uri' => 'c1.vcf', 'confirm' => true]]],
			'delete when the owner changes during the backup' => [fn () => $this->scene->duringBackup = function (): void {
				$this->scene->contacts->books[1]['ownerPrincipal'] = 'principals/users/carla';
			}, ['call', 'contacts_delete_contact', ['addressbook' => $t, 'uri' => 't1.vcf', 'confirm' => true, 'confirm_shared' => true]]],
			'delete when the book id changes during the backup' => [fn () => $this->scene->duringBackup = function (): void {
				$this->scene->contacts->books[0]['id'] = 9;
			}, ['call', 'contacts_delete_contact', ['addressbook' => $p, 'uri' => 'c1.vcf', 'confirm' => true]]],
		];
	}

	/**
	 * @param list<array{0:string, 1:string, 2:array<string, mixed>|\Closure(): array<string, mixed>}|\Closure(): mixed> $steps one scenario
	 * @return list<array<string, mixed>> what each step answered and left behind
	 */
	private function scenario(array $steps): array {
		$this->scene = new ContactsScene($this);
		$this->plan = [];
		$out = [];
		foreach ($steps as $step) {
			if ($step instanceof \Closure) {
				$step();
				continue;
			}
			[$kind, $tool, $arguments] = $step;
			$arguments = $arguments instanceof \Closure ? $arguments() : $arguments;
			$dav = count($this->scene->contacts->log);
			$saves = count($this->scene->saves);
			$searches = count($this->scene->searches);
			$outcome = $this->outcome(fn (): array => match ($kind) {
				'preview' => $this->plan = $this->scene->module()->preview($tool, $arguments, 'alice'),
				'call' => $this->scene->module()->call($tool, $arguments, 'alice'),
				'definitions' => $this->scene->module()->definitions(),
			});
			$out[] = self::bytes(['step' => trim($kind . ' ' . $tool), 'arguments' => $arguments, 'outcome' => $outcome,
				'dav' => array_slice($this->scene->contacts->log, $dav), 'backups' => array_slice($this->scene->saves, $saves),
				'searches' => array_slice($this->scene->searches, $searches)]);
		}
		$out[] = ['cards' => self::bytes($this->scene->contacts->state()['cards'])];
		return $out;
	}

	/**
	 * @param callable(): array<string, mixed> $call one step
	 * @return array<string, mixed> the answer, or the exception chain that left the module
	 */
	private function outcome(callable $call): array {
		try {
			return ['result' => $call()];
		} catch (\Throwable $e) {
			$chain = [];
			for ($x = $e; $x !== null; $x = $x->getPrevious()) {
				$chain[] = ['class' => $x::class, 'message' => $x->getMessage(), 'code' => $x->getCode()] + (property_exists($x, 'plan') ? ['plan' => $x->plan] : []);
			}
			return ['exception' => $chain];
		}
	}

	/**
	 * @param mixed $value part of a recording
	 * @return mixed the same value with every string that is not valid UTF-8 replaced by its bytes in hex
	 */
	private static function bytes(mixed $value): mixed {
		if (is_array($value)) {
			return array_map(self::bytes(...), $value);
		}
		return is_string($value) && !mb_check_encoding($value, 'UTF-8') ? '<invalid UTF-8 ' . bin2hex($value) . '>' : $value;
	}

	/**
	 * @param string $json the recorded run
	 * @return string the same run with every plan_state HMAC, random UID and MD5 ETag numbered by first appearance
	 */
	private static function normalized(string $json): string {
		foreach (['/(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])/' => 'HMAC', '/(?<![0-9a-f])[0-9a-f]{32}(?![0-9a-f])/' => 'MD5'] as $pattern => $label) {
			$seen = [];
			$json = (string)preg_replace_callback($pattern, static function (array $m) use (&$seen, $label): string {
				return $label . '-' . ($seen[$m[0]] ??= count($seen) + 1);
			}, $json);
		}
		return $json;
	}
}
