<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

/** Bounded return-value interpreter for the SHA-pinned adapter/DBAL/driver fetch delegation, never executed. */
final class CollectivesSqlTypes {
	/** @var array<string,string> every body visited by the proof */
	public array $methods = [];
	/** @var array<string,true> active calls */
	private array $reading = [];

	/**
	 * @param CallGraphIndex $index the actual server and bundled Doctrine declarations
	 * @param string $driver concrete Driver\Result implementation for this proof branch
	 */
	public function __construct(private CallGraphIndex $index, private string $driver) {
	}

	/**
	 * @param string $class concrete receiving class
	 * @param string $method fetch method
	 * @param list<?string> $values literal argument evidence (symbols or @array types)
	 * @return string|null the union-free proved return representation, or unknown
	 */
	public function call(string $class, string $method, array $values = []): ?string {
		if ($class === 'PDOStatement') {
			$r = new ReflectionClass($class);
			if (!$r->isInternal() || $r->hasMethod('__toString')) {
				return null;
			}
			$mode = $values[0] ?? null;
			if (!in_array($mode, ['PDO::FETCH_ASSOC', 'PDO::FETCH_NUM', 'PDO::FETCH_COLUMN'], true)) {
				return null;
			}
			return match ($method) {
				'fetch' => $mode === 'PDO::FETCH_COLUMN' ? '@' : '@scalar[]',
				'fetchAll' => $mode === 'PDO::FETCH_COLUMN' ? '@scalar[]' : '@scalar[][]',
				default => null,
			};
		}
		if ($class === 'Doctrine\\DBAL\\Driver\\Result') {
			$class = $this->driver;
		}
		$key = $class . '::' . $method;
		if (isset($this->reading[$key]) || !isset($this->index->classes[$class]['methods'][strtolower($method)])) {
			return null;
		}
		$unit = $this->index->unit($key);
		$this->methods[$key] = (new Standard())->prettyPrint([$unit['node']]);
		$locals = [];
		foreach ($unit['node']->params as $i => $param) {
			$locals[$param->var->name] = array_key_exists($i, $values) ? $values[$i] : ($param->default === null ? null : $this->expr($class, $param->default, []));
		}
		$this->reading[$key] = true;
		try {
			$returns = $this->statements($class, $unit['node']->stmts ?? [], $locals);
			$returns = array_values(array_unique($returns, SORT_REGULAR));
			return count($returns) === 1 ? $returns[0] : null;
		} finally {
			unset($this->reading[$key]);
		}
	}

	/**
	 * @param string $class code context
	 * @param list<Node> $nodes sequential statements
	 * @param array<string,?string> $locals literal/type evidence
	 * @return list<?string> reachable return types; exception paths do not return a value
	 */
	private function statements(string $class, array $nodes, array &$locals): array {
		$returns = [];
		foreach ($nodes as $node) {
			if ($node instanceof Stmt\Return_) {
				$returns[] = $node->expr === null ? '@' : $this->expr($class, $node->expr, $locals);
			} elseif ($node instanceof Stmt\TryCatch) {
				array_push($returns, ...$this->statements($class, $node->stmts, $locals));
				foreach ($node->catches as $catch) {
					array_push($returns, ...$this->statements($class, $catch->stmts, $locals));
				}
			} elseif ($node instanceof Stmt\Expression && $node->expr instanceof Expr\Assign && $node->expr->var instanceof Expr\Variable) {
				$locals[$node->expr->var->name] = $this->expr($class, $node->expr->expr, $locals);
			} elseif ($node instanceof Stmt\Expression && $node->expr instanceof Expr\FuncCall
				&& $node->expr->name instanceof Node\Name && $node->expr->name->toString() === 'oci_fetch_all') {
				$call = $node->expr;
				$out = $call->args[1]->value ?? null;
				$flags = isset($call->args[4]) ? $this->expr($class, $call->args[4]->value, $locals) : null;
				if ($out instanceof Expr\Variable) {
					$locals[$out->name] = self::oci($flags, true);
				}
			} elseif ($node instanceof Stmt\If_) {
				// These pinned fetch bodies only throw on the error branch. Any returned or assigned branch is unmodelled.
				foreach ([$node->stmts, ...array_map(static fn ($n): array => $n->stmts, $node->elseifs), $node->else?->stmts ?? []] as $branch) {
					foreach ($branch as $statement) {
						if (!$statement instanceof Stmt\Expression || !$statement->expr instanceof Expr\Throw_) {
							return [null];
						}
					}
				}
			} elseif (!$node instanceof Stmt\Nop && !($node instanceof Stmt\Expression && $node->expr instanceof Expr\Throw_)) {
				return [null];
			}
		}
		return $returns;
	}

	/**
	 * @param string $class code context
	 * @param Expr $node expression to interpret
	 * @param array<string,?string> $locals proved values
	 * @return string|null literal constant symbol, @type, or unknown
	 */
	private function expr(string $class, Expr $node, array $locals): ?string {
		if ($node instanceof Expr\Variable && is_string($node->name)) {
			return $node->name === 'this' ? $class : ($locals[$node->name] ?? null);
		}
		if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
			return $node->class->toString() . '::' . $node->name->name;
		}
		if ($node instanceof Expr\ConstFetch) {
			return $node->name->toString();
		}
		if ($node instanceof Expr\BinaryOp\BitwiseOr) {
			$a = $this->expr($class, $node->left, $locals);
			$b = $this->expr($class, $node->right, $locals);
			return $a === null || $b === null ? null : $a . '|' . $b;
		}
		if ($node instanceof Expr\PropertyFetch && $node->name instanceof Node\Identifier && $node->var instanceof Expr\Variable && $node->var->name === 'this') {
			return $this->index->props($class)[$node->name->name] ?? null;
		}
		if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
			$receiver = $this->expr($class, $node->var, $locals);
			$args = array_map(fn (Node\Arg $arg): ?string => $this->expr($class, $arg->value, $locals), $node->args);
			return $receiver === null ? null : $this->call($receiver, $node->name->name, $args);
		}
		if ($node instanceof Expr\Match_) {
			$value = $this->expr($class, $node->cond, $locals);
			if ($value === null) {
				return null;
			}
			foreach ($node->arms as $arm) {
				foreach ($arm->conds ?? [] as $condition) {
					if ($this->expr($class, $condition, $locals) === $value) {
						return $this->expr($class, $arm->body, $locals);
					}
				}
			}
			return null;
		}
		if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && $node->name->toString() === 'oci_fetch_array') {
			return self::oci(isset($node->args[1]) ? $this->expr($class, $node->args[1]->value, $locals) : null, false);
		}
		if ($node instanceof Expr\ArrayDimFetch) {
			$type = $this->expr($class, $node->var, $locals);
			$element = $type !== null && str_starts_with($type, '@') ? CallGraphTypes::element(substr($type, 1)) : null;
			return $element === null ? null : '@' . $element;
		}
		return null;
	}

	/**
	 * @param string|null $flags union of literal OCI flag symbols, never a guessed variable
	 * @param bool $all whether rows are fetched as a batch
	 * @return string|null string/null columns only when OCI_RETURN_LOBS is proved on the actual leaf
	 */
	public static function oci(?string $flags, bool $all): ?string {
		$parts = $flags === null ? [] : explode('|', $flags);
		if (array_diff($parts, ['OCI_ASSOC', 'OCI_NUM', 'OCI_RETURN_NULLS', 'OCI_RETURN_LOBS', 'OCI_FETCHSTATEMENT_BY_ROW', 'OCI_FETCHSTATEMENT_BY_COLUMN']) !== []
			|| !in_array('OCI_RETURN_LOBS', $parts, true) || array_intersect(['OCI_ASSOC', 'OCI_NUM'], $parts) === []) {
			return null;
		}
		return $all ? '@scalar[][]' : '@scalar[]';
	}
}
