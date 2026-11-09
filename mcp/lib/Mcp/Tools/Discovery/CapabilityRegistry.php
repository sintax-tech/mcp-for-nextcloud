<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools\Discovery;

use Closure;
use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use Psr\Log\LoggerInterface;

/**
 * Answers the capability keys a tool requires ({@see \OCA\Mcp\Mcp\Tools\ToolDefinition::$requires}) from the probes
 * the modules registered, once per request.
 *
 * A probe is about the installation, so the answer is cached by key alone, without the user. It fails closed: a key
 * no probe answers is false, and a probe that fails anywhere (building it, keys() or supports()) counts as false for
 * its keys, never for the keys of another probe, with one warning in the log per probe and request. The probes are
 * built the first time a key is asked, never before.
 */
final class CapabilityRegistry {
	/** @var array<string, bool> answers of this request, by key */
	private array $answers = [];

	/** @var array<string, int|string>|null entry of $probes answering each key; null until a key is first asked */
	private ?array $owners = null;

	/** @var array<int|string, CapabilityProbe> probes built so far, by entry */
	private array $built = [];

	/** @var array<int|string, true> entries already reported in the log */
	private array $warned = [];

	/**
	 * @param array<int|string, CapabilityProbe|Closure(): CapabilityProbe> $probes probes of every module, or factories
	 *     that build one when first asked; a string key names the probe in the log (its class)
	 * @param LoggerInterface $logger receives the warning of a failing probe
	 */
	public function __construct(
		private readonly array $probes,
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
		$entry = $this->owners()[$key] ?? null;
		if ($entry === null) {
			return false;
		}
		try {
			return $this->built[$entry]->supports($key) === true;
		} catch (\Throwable $e) {
			$this->warn($entry, $e);
			return false;
		}
	}

	/**
	 * Builds every probe and indexes it by the keys it answers; the first probe registered for a key answers it. A
	 * probe that cannot be built or cannot list its keys answers none, and the others are still indexed.
	 *
	 * @return array<string, int|string> entry of $probes by key
	 */
	private function owners(): array {
		if ($this->owners !== null) {
			return $this->owners;
		}
		$owners = [];
		foreach ($this->probes as $entry => $probe) {
			try {
				$built = $probe instanceof Closure ? $probe() : $probe;
				if (!$built instanceof CapabilityProbe) {
					throw new \UnexpectedValueException('not a ' . CapabilityProbe::class);
				}
				$keys = $built->keys();
			} catch (\Throwable $e) {
				$this->warn($entry, $e);
				continue;
			}
			$this->built[$entry] = $built;
			foreach ($keys as $key) {
				$owners[$key] ??= $entry;
			}
		}
		return $this->owners = $owners;
	}

	/**
	 * Logs a failing probe once per request, with its name and the exception class, never the message.
	 *
	 * @param int|string $entry entry of $probes that failed
	 * @param \Throwable $e what the probe threw
	 */
	private function warn(int|string $entry, \Throwable $e): void {
		if (isset($this->warned[$entry])) {
			return;
		}
		$this->warned[$entry] = true;
		$probe = $this->probes[$entry];
		$name = is_string($entry) ? $entry : ($probe instanceof CapabilityProbe ? $probe::class : 'probe #' . $entry);
		try {
			$this->logger->warning('MCP capability probe failed: {probe}: {exception_class} (code {exception_code})', ['app' => 'mcp', 'probe' => $name, 'exception_class' => $e::class, 'exception_code' => ExceptionDetails::code($e)]);
		} catch (\Throwable) {
			// The answer is already false; a failing logger changes nothing.
		}
	}
}
