<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

/** Readback probe whose missing-version getter always fails, independently of MCP's output keys. */
final class VersionedOmissionProbe {
	/** @var int actual getter calls */
	public int $calls = 0;
	/**
	 * Stores native-observable scalars and independent getter availability.
	 * @param array<string,mixed> $values source-shaped values
	 * @param bool $available whether this pin has the getter
	 * @return void
	 */
	public function __construct(private array $values, private bool $available) {}
	/**
	 * Serves only fields in the explicitly supplied schema.
	 * @param string $method public native getter
	 * @param array<mixed> $args no getter arguments
	 * @return mixed original field
	 */
	public function __call(string $method,array $args): mixed {
		$key=lcfirst(substr($method,3));
		if(!str_starts_with($method,'get')||$args!==[]||!array_key_exists($key,$this->values)){throw new \LogicException('Undeclared getter');}
		return $this->values[$key];
	}
	/** @return string|null native public normalization; missing pins cannot call this API */
	public function getTimezoneName(): ?string {
		$this->calls++;
		if(!$this->available){throw new \LogicException('Getter is absent in this pin');}
		return null;
	}
}
