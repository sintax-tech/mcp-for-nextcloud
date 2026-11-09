<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Discovery;

use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\FeatureProbe;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Capabilities fail closed, are cached by key for the request and build the probes only when first asked. */
final class CapabilityRegistryTest extends TestCase {
	/** A key no probe answers is false; no key at all holds. */
	public function testAKeyWithoutAProbeIsFalse(): void {
		$registry = new CapabilityRegistry([new FeatureProbe(true)], $this->createMock(LoggerInterface::class));
		self::assertFalse($registry->supports('deck.dependencies'));
		self::assertTrue($registry->supports('probe.feature'));
		self::assertTrue($registry->supportsAll([]));
		self::assertFalse($registry->supportsAll(['probe.feature', 'deck.dependencies']));
	}

	/** The answer is cached by key: the probe runs once per key and request, whoever asks. */
	public function testTheAnswerIsCachedByKey(): void {
		$probe = new FeatureProbe(true, ['probe.feature', 'probe.other']);
		$registry = new CapabilityRegistry([$probe], $this->createMock(LoggerInterface::class));
		foreach ([1, 2, 3] as $ignored) {
			self::assertTrue($registry->supports('probe.feature'));
		}
		self::assertSame(1, $probe->asked);
		self::assertTrue($registry->supports('probe.other'));
		self::assertSame(2, $probe->asked);
	}

	/** A probe that throws counts as false, with one warning per probe and request, its class and no message. */
	public function testAThrowingProbeIsFalseWithOneWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('MCP capability probe failed: {probe}: {exception_class} (code {exception_code})',
			['app' => 'mcp', 'probe' => FeatureProbe::class, 'exception_class' => \RuntimeException::class, 'exception_code' => 0]);
		$registry = new CapabilityRegistry([new FeatureProbe(new \RuntimeException('secret path'), ['probe.a', 'probe.b'])], $logger);
		foreach (['probe.a', 'probe.b', 'probe.a'] as $key) {
			self::assertFalse($registry->supports($key), $key);
		}
	}

	/** A probe that cannot be built answers false for its keys only, once, and leaves the other probes answering. */
	public function testAProbeThatCannotBeBuiltIsIsolated(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('MCP capability probe failed: {probe}: {exception_class} (code {exception_code})',
			['app' => 'mcp', 'probe' => 'Broken\\Probe', 'exception_class' => \RuntimeException::class, 'exception_code' => 0]);
		$built = 0;
		$registry = new CapabilityRegistry([
			'Broken\\Probe' => static function () use (&$built): FeatureProbe {
				$built++;
				throw new \RuntimeException('constructor failed');
			},
			FeatureProbe::class => static fn (): FeatureProbe => new FeatureProbe(true, ['probe.other']),
		], $logger);
		foreach ([1, 2] as $ignored) {
			self::assertTrue($registry->supports('probe.other'));
			self::assertFalse($registry->supports('probe.feature'));
		}
		self::assertSame(1, $built);
	}

	/** A probe whose keys cannot be read answers nothing; the others still do. A failing logger changes nothing. */
	public function testAProbeWithBrokenKeysIsSkipped(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willThrowException(new \RuntimeException('log down'));
		$registry = new CapabilityRegistry([new FeatureProbe(true, new \Error('autoload')), new FeatureProbe(true, ['probe.other'])], $logger);
		self::assertFalse($registry->supports('probe.feature'));
		self::assertTrue($registry->supports('probe.other'));
	}

	/** The first probe registered for a key answers it. */
	public function testTheFirstProbeOfAKeyAnswers(): void {
		$registry = new CapabilityRegistry([new FeatureProbe(false), new FeatureProbe(true)], $this->createMock(LoggerInterface::class));
		self::assertFalse($registry->supports('probe.feature'));
	}

	/** A probe given as a factory is built only when the first key is asked, and once. */
	public function testProbesAreBuiltOnlyWhenAsked(): void {
		$built = 0;
		$probes = [FeatureProbe::class => static function () use (&$built): FeatureProbe {
			$built++;
			return new FeatureProbe(true);
		}];
		$registry = new CapabilityRegistry($probes, $this->createMock(LoggerInterface::class));
		self::assertSame(0, $built);
		self::assertTrue($registry->supports('probe.feature'));
		self::assertTrue($registry->supports('probe.feature'));
		self::assertSame(1, $built);
	}
}
