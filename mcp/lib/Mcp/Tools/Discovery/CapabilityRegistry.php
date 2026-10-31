<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools\Discovery;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use Psr\Log\LoggerInterface;

/**
 * Answers the capability keys a tool requires ({@see \OCA\Mcp\Mcp\Tools\ToolDefinition::$requires}) from the probes
 * the modules registered, once per request.
 *
 * A probe is about the installation, so the answer is cached by key alone, without the user. It fails closed: a key
 * no probe answers is false, and a probe that throws (in keys() or supports()) counts as false, with one warning in
 * the log per key and request. The probes are built the first time a key is asked, never before.
 */
final class CapabilityRegistry {
	/** @var array<string, bool> answers of this request, by key */
	private array $answers = [];

	/** @var array<string, CapabilityProbe>|null probe answering each key; null until a key is first asked */
	private ?array $owners = null;

	/**
	 * @param iterable<CapabilityProbe> $probes probes of every module; a lazy iterable is read on the first question
	 * @param LoggerInterface $logger receives the warning of a failing probe
	 */
	public function __construct(
		private readonly iterable $probes,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param list<string> $keys capability keys, `<module>.<capability>`
	 * @return bool true when every key holds (true for no key)
	 */
	public function supportsAll(array $keys): bool {
		foreach ($keys as $key) {
			if (!$this->supports($key)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $key capability key, `<module>.<capability>`
	 * @return bool the answer of the probe of that key; false without a probe or when the probe fails
	 */
	public function supports(string $key): bool {
		return $this->answers[$key] ??= $this->ask($key);
	}

	/**
	 * Asks the probe of a key, failing closed.
	 *
	 * @param string $key capability key
	 * @return bool the answer
	 */
	private function ask(string $key): bool {
		$probe = $this->owners()[$key] ?? null;
		if ($probe === null) {
			return false;
		}
		try {
			return $probe->supports($key) === true;
		} catch (\Throwable $e) {
			$this->warn($key, $e);
			return false;
		}
	}

	/**
	 * Indexes the probes by the keys they answer; the first probe registered for a key answers it.
	 *
	 * @return array<string, CapabilityProbe> probe by key
	 */
	private function owners(): array {
		if ($this->owners !== null) {
			return $this->owners;
		}
		$owners = [];
		foreach ($this->probes as $probe) {
			try {
				$keys = $probe->keys();
			} catch (\Throwable $e) {
				$this->warn($probe::class, $e);
				continue;
			}
			foreach ($keys as $key) {
				$owners[$key] ??= $probe;
			}
		}
		return $this->owners = $owners;
	}

	/**
	 * Logs a failing probe with its class and the exception class, never the message.
	 *
	 * @param string $subject capability key, or the probe class when its keys could not be read
	 * @param \Throwable $e what the probe threw
	 */
	private function warn(string $subject, \Throwable $e): void {
		try {
			$this->logger->warning('MCP capability probe failed', ['app' => 'mcp', 'capability' => $subject, 'exception_class' => $e::class]);
		} catch (\Throwable) {
			// The answer is already false; a failing logger changes nothing.
		}
	}
}
