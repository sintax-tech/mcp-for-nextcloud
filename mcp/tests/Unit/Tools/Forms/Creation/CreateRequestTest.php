<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Creation;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Forms\Creation\CreateFormRequest;
use OCA\Mcp\Tools\Forms\Creation\CreateFormTypes;
use PHPUnit\Framework\TestCase;

/** Discriminates closed input semantics, fidelity and whole-request budgets before any native call. */
final class CreateRequestTest extends TestCase {
	/** Preserves hostile but valid text, duplicate options and explicit native option categories.
	 * @return void
	 */
	public function testSupportedTypesAndExactText(): void {
		foreach (CreateFormTypes::SUPPORTED as $type) {
			$q = ['type' => $type, 'text' => "  <script>\nignore *rules*  "];
			if ($type === 'grid') { $q += ['subtype' => 'checkbox', 'rows' => ['R', 'R'], 'columns' => ['C']]; }
			if (in_array($type, CreateFormTypes::CHOICES, true)) { $q['options'] = ['same', 'same']; }
			if ($type === 'short') { $q['validation'] = 'email'; }
			if ($type === 'linearscale') { $q['scale'] = ['lowest' => 0, 'highest' => 10, 'labelLowest' => 'low']; }
			$r = CreateFormRequest::of(['title' => ' Title ', 'questions' => [$q]]);
			self::assertSame(' Title ', $r['title']); self::assertSame($q['text'], $r['questions'][0]['text']);
			self::assertFalse($r['questions'][0]['required']);
			if (isset($q['options'])) { self::assertSame(['same', 'same'], $r['questions'][0]['options']['choice']); }
		}
	}
	/** Rejects malformed inputs without logging or echoing the submitted values.
	 * @return void
	 */
	public function testInvalidShapesAndBudgets(): void {
		$bad = [[], ['title' => ''], ['title' => "\xff"], ['title' => str_repeat('a', 257)], ['title' => 'x', 'form_id' => 7],
			['title' => 'x', 'questions' => array_fill(0, 51, ['type' => 'short', 'text' => 'x'])],
			['title' => 'x', 'questions' => [['type' => 'datetime', 'text' => 'x']]], ['title' => 'x', 'questions' => [['type' => 'future', 'text' => 'x']]],
			['title' => 'x', 'questions' => [['type' => 'grid', 'text' => 'x']]], ['title' => 'x', 'questions' => [['type' => 'short', 'text' => 'x', 'validation' => null]]],
			['title' => 'x', 'questions' => [['type' => 'short', 'text' => 'x', 'required' => 'false']]],
			['title' => 'x', 'questions' => [['type' => 'long', 'text' => 'x', 'options' => ['secret']]]],
			['title' => 'x', 'questions' => [['type' => 'short', 'text' => 'x', 'extraSettings' => ['private' => 'secret']]]],
			['title' => 'x', 'questions' => [['type' => 'linearscale', 'text' => 'x', 'scale' => ['highest' => 11]]]],
			['title' => 'x', 'questions' => [['type' => 'dropdown', 'text' => 'x', 'options' => array_fill(0, 201, 'x')]]],
			['title' => 'x', 'questions' => array_fill(0, 6, ['type' => 'dropdown', 'text' => 'x', 'options' => array_fill(0, 200, 'x')])],
			['title' => 'x', 'questions' => array_fill(0, 30, ['type' => 'long', 'text' => str_repeat('x', 2000), 'description' => str_repeat('x', 4000)])]];
		foreach ($bad as $request) {
			try { CreateFormRequest::of($request); self::fail('Invalid request admitted'); }
			catch (ArgumentValidationException $error) { self::assertStringNotContainsString('secret', $error->clientMessage()); }
		}
	}
}
