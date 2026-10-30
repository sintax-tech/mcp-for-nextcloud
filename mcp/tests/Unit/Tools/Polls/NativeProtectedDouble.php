<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Polls\Model\User {
	/** Native anonymous type discriminator, logged-in context is required by the real serializer. */
	final class Anon {
		/** Native type. */
		public const TYPE = 'anonymous';
	}
}
namespace OCA\Polls\Db {
	use OCA\Mcp\Tests\Unit\Tools\Polls\{NativeProtectedScene, NativeScene};
	/** Strict native vote shape, including an independent ownership predicate. */
	class Vote implements \JsonSerializable {
		/**
		 * Receives native row.
		 * @param array<string,mixed> $row native values
		 * @return void
		 */
		public function __construct(public array $row) {
		}
		/** @return bool native ownership predicate */
		public function getCurrentUserIsEntityUser(): bool {
			return ($this->row['owner'] ?? '') === NativeScene::session()->getCurrentUser()->getId();
		}
		/** @return array<string,mixed> native projection, deliberately rich */
		public function jsonSerialize(): array {
			NativeProtectedScene::$serializations++;
			return $this->row;
		}
	}
	/** Strict share: public URL getters only; any full serialization is a test error. */
	class Share {
		/** Native user type. */
		public const TYPE_USER = 'user';
		/** Native delegated editor type. */
		public const TYPE_ADMIN = 'admin';
		/** Native group type. */
		public const TYPE_GROUP = 'group';
		/** Native public type. */
		public const TYPE_PUBLIC = 'public';
		/** Native email type. */
		public const TYPE_EMAIL = 'email';
		/** Native contact type. */
		public const TYPE_CONTACT = 'contact';
		/** Native external type. */
		public const TYPE_EXTERNAL = 'external';
		/** Native team/container type. */
		public const TYPE_TEAM = 'circle';
		/** Native contact group container type. */
		public const TYPE_CONTACTGROUP = 'contactGroup';
		/**
		 * Receives native row.
		 * @param array<string,mixed> $row values
		 * @return void
		 */
		public function __construct(public array $row) {
		}
		/** @return mixed dynamic native identifier */
		public function getId(): mixed { return $this->row['id']; }
		/** @return mixed dynamic native poll */
		public function getPollId(): mixed { return $this->row['pollId']; }
		/** @return mixed dynamic native group */
		public function getGroupId(): mixed { return $this->row['groupId'] ?? null; }
		/** @return mixed native deleted integer */
		public function getDeleted(): mixed { return $this->row['deleted'] ?? 0; }
		/** @return mixed native locked integer */
		public function getLocked(): mixed { return $this->row['locked'] ?? 0; }
		/** @return string native type, with deleted/locked admin downgrade */
		public function getType(): string {
			return $this->row['type'] === 'admin' && ($this->getDeleted() || $this->getLocked()) ? 'user' : $this->row['type'];
		}
		/** @return string native URL, never reconstructed by the MCP projection */
		public function getURL(): string { return $this->row['url']; }
	}
}
namespace OCA\Polls\Service {
	use OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene;
	/** Exact public native list signature, no effect APIs implemented. */
	class ShareService {
		/**
		 * V9 effect API exists only to detect an unauthorized call, not to simulate it as an inert list.
		 * @param string|null $token native optional token
		 * @return \OCA\Polls\Db\Share synthetic result after the recorded forbidden effect boundary
		 */
		public function getEffectiveShare(?string $token = null): \OCA\Polls\Db\Share {
			NativeProtectedScene::$effects++;
			return new \OCA\Polls\Db\Share(['id' => 999, 'pollId' => 1, 'type' => 'public', 'url' => 'https://example.test/unused']);
		}
		/**
		 * Lists editor-visible native shares.
		 * @param int $pollId poll
		 * @param string $purpose native purpose
		 * @return array<\OCA\Polls\Db\Share> shares
		 */
		public function list(int $pollId, string $purpose = 'poll'): array {
			return NativeProtectedScene::shares($pollId, $purpose);
		}
	}
}
