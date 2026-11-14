<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

/**
 * The part of Horde_Mime_Part the attachment preflight reads, for a message structure built in a test: type, name,
 * charset, Horde's attachment rule and the lookup of a part by its MIME number. Part numbers follow IMAP: the children
 * of a multipart root are "1", "2", …, nested ones "2.1", and a single-part message is part "1".
 */
final class FakeMimePart {
	/** Declared native byte upper bound used only by binary save tests. */
	public int $bytes = 100;
	/** @return int Native declared bytes. */
	public function getBytes(): int { return $this->bytes; }
	/** @return list<FakeMimePart> Child parts. */
	public function getParts(): array { return $this->children; }
	/** @var list<FakeMimePart> child parts */
	private array $children = [];

	/** MIME part number, set when the tree is numbered. */
	private string $mimeId = '0';

	/**
	 * Describes one part.
	 *
	 * @param string $type MIME type
	 * @param string|null $name file name (Content-Disposition filename or Content-Type name)
	 * @param string|null $disposition Content-Disposition value
	 * @param string|null $charset charset parameter
	 * @return void
	 */
	public function __construct(
		private string $type,
		private ?string $name = null,
		private ?string $disposition = null,
		private ?string $charset = null,
	) {
	}

	/**
	 * A multipart root numbered as IMAP numbers it.
	 *
	 * @param string $type multipart type, such as multipart/mixed
	 * @param list<FakeMimePart> $children its parts
	 * @return self the root, part "0"
	 */
	public static function multipart(string $type, array $children): self {
		$root = new self($type);
		$root->children = $children;
		$root->number('0');
		return $root;
	}

	/**
	 * A single-part message, numbered "1".
	 *
	 * @param FakeMimePart $part the only part
	 * @return self the part
	 */
	public static function single(FakeMimePart $part): self {
		$part->number('1');
		return $part;
	}

	/**
	 * A part with parts of its own (a nested multipart, or an embedded message/rfc822), to be placed among the
	 * children of another one.
	 *
	 * @param string $type its type
	 * @param list<FakeMimePart> $children its parts
	 * @param string|null $name its file name
	 * @param string|null $disposition its Content-Disposition value
	 * @return self the part
	 */
	public static function nested(string $type, array $children, ?string $name = null, ?string $disposition = null): self {
		$part = new self($type, $name, $disposition);
		$part->children = $children;
		return $part;
	}

	/**
	 * Numbers this part and its descendants.
	 *
	 * @param string $id number of this part
	 * @return void
	 */
	private function number(string $id): void {
		$this->mimeId = $id;
		foreach ($this->children as $index => $child) {
			$child->number($id === '0' ? (string)($index + 1) : $id . '.' . ($index + 1));
		}
	}

	/**
	 * @return string the MIME type
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @return string the primary type, such as "multipart"
	 */
	public function getPrimaryType(): string {
		return explode('/', $this->type)[0];
	}

	/**
	 * @return string|null the file name
	 */
	public function getName(): ?string {
		return $this->name;
	}

	/**
	 * @return string|null the charset
	 */
	public function getCharset(): ?string {
		return $this->charset;
	}

	/**
	 * Horde's rule, reduced to what the tests use: a disposition of attachment, or an application part with a name.
	 *
	 * @return bool whether Horde calls the part an attachment
	 */
	public function isAttachment(): bool {
		return $this->disposition === 'attachment' || ($this->getPrimaryType() === 'application' && $this->name !== null);
	}

	/**
	 * The part with that number in this tree.
	 *
	 * @param string $id MIME part number
	 * @return self|null the part, or null
	 */
	public function getPart(string $id): ?self {
		if ($id === $this->mimeId) {
			return $this;
		}
		foreach ($this->children as $child) {
			$found = $child->getPart($id);
			if ($found !== null) {
				return $found;
			}
		}
		return null;
	}
}
