<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Parameter evidence comes from every reached call site; an unknown argument is never discarded. */
final class CallGraphBindings {
	/** @var array<string, array<string, array<string, list<?string>>>> callee, call site, parameter, types */
	private array $sites = [];

	/** @return void starts an independent reachability query */
	public function clear(): void {
		$this->sites = [];
	}

	/**
	 * @param string $callee canonical body key
	 * @param string $site stable caller and source position
	 * @param array<string, list<?string>> $arguments types proved for its supplied parameters
	 * @return void replaces the previous round's evidence from this call site
	 */
	public function record(string $callee, string $site, array $arguments): void {
		$this->sites[$callee][$site] = $arguments;
	}

	/**
	 * @param string $callee a canonical body key
	 * @return array<string, list<?string>> the union of every reached argument for each parameter
	 */
	public function of(string $callee): array {
		$types = [];
		foreach ($this->sites[$callee] ?? [] as $arguments) {
			foreach ($arguments as $name => $values) {
				$types[$name] = CallGraphTypes::union([...($types[$name] ?? []), ...$values]);
			}
		}
		return $types;
	}

	/** @return string stable convergence token for the complete call-site evidence */
	public function snapshot(): string {
		return serialize($this->sites);
	}
}
