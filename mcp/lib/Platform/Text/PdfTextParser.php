<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Text;

use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;

/**
 * smalot/pdfparser's Parser that reads every name of an object stored in an object stream, through the protected
 * parseObject() extension point and without changing the library: the decoded content of each object stream goes
 * through {@see PdfObjectStreamNames} before the parser splits it, and every other object is parsed as before.
 */
class PdfTextParser extends Parser {
	/**
	 * Parses one object of the file, escaping the names of an object stream first.
	 *
	 * @param string $id the object identifier, `<number>_<generation>`
	 * @param array<int, mixed> $structure the object as the raw data parser read it
	 * @param Document|null $document the document being built
	 * @return void
	 */
	protected function parseObject(string $id, array $structure, ?Document $document) {
		parent::parseObject($id, $this->escaped($structure, $document), $document);
	}

	/**
	 * The structure of an object stream with its decoded content escaped; any other structure as it is.
	 *
	 * @param array<int, mixed> $structure the object as the raw data parser read it
	 * @param Document|null $document the document being built
	 * @return array<int, mixed> the structure the parser reads
	 */
	private function escaped(array $structure, ?Document $document): array {
		$dictionary = null;
		foreach ($structure as $position => $part) {
			if (!is_array($part)) {
				continue;
			}
			if ($part[0] === '<<') {
				$dictionary = $part[1];
			} elseif ($part[0] === 'stream' && $dictionary !== null
				&& $this->parseHeader($dictionary, $document)->get('Type')->equals('ObjStm')) {
				// The parser reads the decoded content when there is one, the raw content otherwise.
				if (isset($part[3][0])) {
					$structure[$position][3][0] = PdfObjectStreamNames::escaped($part[3][0]);
				} else {
					$structure[$position][1] = PdfObjectStreamNames::escaped($part[1]);
				}
			}
		}
		return $structure;
	}
}
