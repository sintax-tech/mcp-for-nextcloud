<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Fetch types are enabled only by an independently reconstructed pinned adapter/DBAL/driver proof. */
final class CallGraphFetchTypes {
	/** @param array<string,array<string,string>> $proof literal mode to proved return type, from CollectivesSqlProof */
	public function __construct(private array $proof) {
	}

	/**
	 * @param string $target canonical resolved declaration, never an app override
	 * @param Expr\MethodCall|Expr\NullsafeMethodCall $call actual call and its mode expression
	 * @return array{bool,?string} whether this is a proved platform fetch model, and its return or unknown
	 */
	public function result(string $target, Expr $call): array {
		[$class, $method] = explode('::', $target);
		if ($class !== 'OCP\\DB\\IResult' || !isset($this->proof[$method])) {
			return [false, null];
		}
		$mode = $call->args[0]->value ?? null;
		if ($mode === null) {
			return [true, $this->proof[$method]['PDO::FETCH_ASSOC'] ?? null];
		}
		if ($mode instanceof Expr\ClassConstFetch && $mode->class instanceof Node\Name && $mode->class->toString() === 'PDO' && $mode->name instanceof Node\Identifier) {
			return [true, $this->proof[$method]['PDO::' . $mode->name->name] ?? null];
		}
		if ($mode instanceof Node\Scalar\Int_) {
			foreach (['FETCH_ASSOC', 'FETCH_NUM', 'FETCH_COLUMN'] as $name) {
				if ($mode->value === constant('PDO::' . $name)) {
					return [true, $this->proof[$method]['PDO::' . $name] ?? null];
				}
			}
		}
		return [true, null];
	}
}
