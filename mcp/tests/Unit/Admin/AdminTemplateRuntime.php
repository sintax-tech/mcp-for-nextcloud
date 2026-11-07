<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCP {
	/** Asset recorder used only by the isolated template render test. */
	final class Util {
		/** @var list<array{string, string, string}> */
		public static array $assets = [];
		/** @param string $app app name @param string $name asset name @return void */
		public static function addScript(string $app, string $name): void { self::$assets[] = ['script', $app, $name]; }
		/** @param string $app app name @param string $name asset name @return void */
		public static function addStyle(string $app, string $name): void { self::$assets[] = ['style', $app, $name]; }
	}
}
namespace {
	if (!function_exists('p')) {
		/** @param mixed $value text @return void */
		function p($value): void { echo htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
	}
	if (!function_exists('print_unescaped')) {
		/** @param string $value already rendered template @return void */
		function print_unescaped(string $value): void { echo $value; }
	}
}
namespace OCA\Mcp\Tests\Unit\Admin {
	/** Execute the original PHP template expressions without a Nextcloud instance. */
	final class AdminTemplateRuntime {
		/** @param string $file template @param array<string, mixed> $parameters explicit template parameters @return string exact emitted bytes */
		public static function render(string $file, array $parameters): string {
			$_ = $parameters;
			$l = new class {
				/** @param string $text source string @param array $parameters positional translations @return string deterministic translated value */
				public function t(string $text, array $parameters = []): string { return $parameters === [] ? $text : vsprintf($text, $parameters); }
			};
			ob_start();
			try { include $file; return (string)ob_get_contents(); }
			finally { ob_end_clean(); }
		}
	}
}
