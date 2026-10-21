<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The store metadata must not silently lose the reviewed upstream 1.1.2 feature descriptions. */
final class ManifestDescriptionParityTest extends TestCase {
    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function storeLocales(): iterable {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../../fixtures/manifest-1.1.2-descriptions.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['locales'] as $locale => $expected) {
            yield $locale => [$locale, $expected['summary'], $expected['paragraphs']];
        }
    }

    /** @param array<string, string> $paragraphs reviewed feature paragraphs, independently captured from upstream */
    #[DataProvider('storeLocales')]
    public function testStoreMetadataRetainsUpstreamFeatureDescriptions(string $locale, string $summary, array $paragraphs): void {
        $manifest = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
        self::assertNotFalse($manifest);
        $selector = $locale === 'en' ? '[not(@lang)]' : '[@lang="' . $locale . '"]';
        $summaries = $manifest->xpath('summary' . $selector);
        $descriptions = $manifest->xpath('description' . $selector);
        self::assertCount(1, $summaries, $locale . ': exactly one summary must be available');
        self::assertCount(1, $descriptions, $locale . ': exactly one description must be available');
        self::assertSame(self::normalize($summary), self::normalize((string)$summaries[0]), $locale . ': group grants must appear in the summary');
        $description = self::normalize((string)$descriptions[0]);
        foreach ($paragraphs as $feature => $paragraph) {
            self::assertStringContainsString(self::normalize($paragraph), $description, $locale . ': missing or changed upstream feature description: ' . $feature);
        }
    }

    /** Ignore formatting changes while retaining the reviewed wording and safety qualifications. */
    private static function normalize(string $text): string {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
