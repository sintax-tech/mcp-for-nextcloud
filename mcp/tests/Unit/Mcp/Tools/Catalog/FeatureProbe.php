<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;

/** A probe answering `probe.feature` with a value, or throwing, as a test sets it. */
final class FeatureProbe implements CapabilityProbe {
	/** @var int how many times supports() ran */
	public int $asked = 0;

	/**
	 * @param bool|\Throwable $answer the answer, or what supports() throws
	 * @param list<string>|\Throwable $keys the keys, or what keys() throws
	 */
	public function __construct(
		private bool|\Throwable $answer = true,
		private array|\Throwable $keys = ['probe.feature'],
	) {
	}

	/** @return list<string> the keys of this probe */
	public function keys(): array {
		if ($this->keys instanceof \Throwable) {
			throw $this->keys;
		}
		return $this->keys;
	}

	/**
	 * @param string $key one of keys()
	 * @return bool the answer set by the test
	 */
	public function supports(string $key): bool {
		$this->asked++;
		if ($this->answer instanceof \Throwable) {
			throw $this->answer;
		}
		return $this->answer;
	}
}
