<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Only the exact audited phputf8 bodies may emit a PHP diagnostic; no other logging is admitted. */
final class CollectivesDiagnostics {
	/**
	 * @param array<string, string> $methods followed bodies, by unit key
	 * @param array<string, list<string>> $allowed audited body SHA-256 values, by unit key
	 * @return void rejects every other trigger_error and every error_log
	 */
	public static function check(array $methods, array $allowed): void {
		$parser = (new ParserFactory())->createForHostVersion();
		foreach ($methods as $method => $body) {
			// Methods need a class wrapper, while followed global functions are standalone declarations.
			$source = str_starts_with($method, 'function ') ? $body : 'class DiagnosticBody { ' . $body . ' }';
			$ast = $parser->parse('<?php ' . $source) ?? [];
			foreach ((new NodeFinder())->findInstanceOf($ast, Expr\FuncCall::class) as $call) {
				$name = $call->name instanceof Node\Name ? strtolower($call->name->toString()) : '';
				if ($name === 'error_log' || ($name === 'trigger_error'
					&& !in_array(hash('sha256', $body), $allowed[$method] ?? [], true))) {
					throw new RuntimeException('Unproved diagnostic in ' . $method . ': ' . $name);
				}
			}
		}
	}
}
