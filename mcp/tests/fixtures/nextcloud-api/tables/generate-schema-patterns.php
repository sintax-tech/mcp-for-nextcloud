<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Extracts native provider-list and text-form semantics from verified official archives. */
final class TablesSchemaPatternSources {
	/** @param string $archives Verified official tag archives. @return void */
	public static function run(string $archives): void {
		$manifest = json_decode(file_get_contents(__DIR__ . '/schema-api.json'), true, 512, JSON_THROW_ON_ERROR);
		$result = [];
		foreach (['1.0.10', '2.2.2', '2.3.1', '2.3.2'] as $pin) {
			$file = $archives . '/v' . $pin . '.tar.gz';
			$hash = $manifest['sources'][$pin]['archiveSha256'];
			if (hash_file('sha256', $file) !== $hash) throw new RuntimeException('Archive hash mismatch');
			$sources = [];
			foreach (new RecursiveIteratorIterator(new PharData($file)) as $entry) {
				if (!$entry->isFile()) continue;
				$name = $entry->getPathname();
				foreach (['lib/Service/ColumnTypes/TextLinkBusiness.php', 'lib/Service/ColumnTypes/TextLineBusiness.php', 'src/shared/components/ncTable/partials/columnTypePartials/forms/TextLineForm.vue', 'src/shared/components/ncTable/partials/columnTypePartials/forms/TextLinkForm.vue'] as $wanted) {
					if (!str_ends_with($name, '/' . $wanted)) continue;
					$source = $entry->getContent();
					$sources[$wanted] = ['sha256' => hash('sha256', $source), 'source' => $source];
				}
			}
			if (count($sources) !== 4) throw new RuntimeException('Missing native pattern proof');
			ksort($sources);
			$result[$pin] = ['archiveSha256' => $hash, 'sources' => $sources];
		}
		file_put_contents(__DIR__ . '/schema-patterns.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
	}
}

TablesSchemaPatternSources::run($argv[1]);
