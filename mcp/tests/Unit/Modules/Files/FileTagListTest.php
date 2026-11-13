<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Modules\Files\Tagging\FileTagList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The projection of the native tag relation answer: what is kept, what is dropped, what is refused. */
final class FileTagListTest extends TestCase {
	/** A relation answer: the collection itself, one tag, one exclusion tag and a tag whose properties were refused. */
	private const ANSWER = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
		. '<d:response><d:href>/remote.php/dav/systemtags-relations/files/42/</d:href>'
		. '<d:propstat><d:prop><oc:id/><oc:display-name/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat></d:response>'
		. '<d:response><d:href>/remote.php/dav/systemtags-relations/files/42/5</d:href>'
		. '<d:propstat><d:prop><oc:id>5</oc:id><oc:display-name>Contract &amp; draft</oc:display-name><oc:user-visible>true</oc:user-visible>'
		. '<oc:user-assignable>false</oc:user-assignable><oc:can-assign>false</oc:can-assign></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
		. '<d:response><d:href>/remote.php/dav/systemtags-relations/files/42/7</d:href>'
		. '<d:propstat><d:prop><oc:id>7</oc:id><oc:display-name>Hide from MCP</oc:display-name></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
		. '<d:response><d:href>/remote.php/dav/systemtags-relations/files/42/8</d:href>'
		. '<d:propstat><d:prop><oc:id>8</oc:id></d:prop><d:status>HTTP/1.1 403 Forbidden</d:status></d:propstat></d:response>'
		. '</d:multistatus>';

	public function testKeepsNativeFlagsAndDropsTheExclusionTagsAndTheCollection(): void {
		self::assertSame(
			[['id' => '5', 'name' => 'Contract & draft', 'user_visible' => true, 'user_assignable' => false, 'can_assign' => false]],
			FileTagList::parse(self::ANSWER, ['7']),
		);
	}

	/** @return iterable<string,array{string}> answers that are not the expected native document */
	public static function malformed(): iterable {
		yield 'empty' => [''];
		yield 'not xml' => ['tags'];
		yield 'other root' => ['<?xml version="1.0"?><d:error xmlns:d="DAV:"/>'];
		yield 'doctype' => ['<?xml version="1.0"?><!DOCTYPE d:multistatus [<!ENTITY x "y">]><d:multistatus xmlns:d="DAV:"/>'];
		yield 'non-numeric id' => [str_replace('<oc:id>5</oc:id>', '<oc:id>5x</oc:id>', self::ANSWER)];
	}

	#[DataProvider('malformed')]
	public function testRefusesAnythingButTheNativeDocument(string $answer): void {
		$this->expectException(RuntimeException::class);
		FileTagList::parse($answer, []);
	}
}
