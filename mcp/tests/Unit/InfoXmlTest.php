<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use DOMDocument;
use LibXMLError;
use PHPUnit\Framework\TestCase;

/**
 * Validates appinfo/info.xml against the official Nextcloud app schema.
 *
 * The schema is vendored as tests/fixtures/info.xsd, copied verbatim from
 * https://apps.nextcloud.com/schema/apps/info.xsd, so the check is hermetic and runs
 * offline. It is the same schema the app store applies on upload, which is stricter than
 * the parser the server uses: element order and required elements such as <bugs> are part
 * of it, so reordering or dropping a child element fails here too.
 */
final class InfoXmlTest extends TestCase {
    /** Official app schema, vendored in tests/fixtures. */
    private const SCHEMA = __DIR__ . '/../fixtures/info.xsd';

    /** App manifest checked against the schema. */
    private const MANIFEST = __DIR__ . '/../../appinfo/info.xml';

    /** The manifest must be well-formed and satisfy the official schema. */
    public function testInfoXmlIsValidAgainstTheOfficialSchema(): void {
        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $document = new DOMDocument();
            $loaded = $document->load(self::MANIFEST);
            $loadErrors = libxml_get_errors();
            libxml_clear_errors();
            $valid = $loaded && $document->schemaValidate(self::SCHEMA);
            $schemaErrors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        $this->assertTrue($loaded, 'appinfo/info.xml must be well-formed XML: ' . $this->messages($loadErrors));
        $this->assertTrue($valid, 'appinfo/info.xml must satisfy ' . basename(self::SCHEMA) . ': ' . $this->messages($schemaErrors));
    }

    /** @return \SimpleXMLElement the parsed manifest */
    private function manifest(): \SimpleXMLElement {
        $manifest = simplexml_load_file(self::MANIFEST);
        $this->assertInstanceOf(\SimpleXMLElement::class, $manifest);
        return $manifest;
    }

    /**
     * @param string $element name, summary or description
     * @return array<string, string> its text per language, "en" for the untagged one
     */
    private function localized(string $element): array {
        $texts = [];
        foreach ($this->manifest()->{$element} as $node) {
            $texts[(string)($node['lang'] ?? 'en')] = trim((string)$node);
        }
        return $texts;
    }

    /** The store shows the name, the summary and the description in English, Brazilian Portuguese and Spanish. */
    public function testNameSummaryAndDescriptionComeInEveryLanguageOfTheApp(): void {
        foreach (['name', 'summary', 'description'] as $element) {
            $texts = $this->localized($element);
            $this->assertSame(['en', 'pt-br', 'es'], array_keys($texts), $element);
            foreach ($texts as $lang => $text) {
                $this->assertNotSame('', $text, "$element ($lang)");
                $this->assertStringNotContainsStringIgnoringCase('test release', $text, "$element ($lang)");
                $this->assertStringNotContainsStringIgnoringCase('versão de teste', $text, "$element ($lang)");
                $this->assertStringNotContainsStringIgnoringCase('dalcomad', $text, "$element ($lang): no customer's data in the store listing");
            }
        }
        $this->assertSame(['en' => 'MCP for Nextcloud', 'pt-br' => 'MCP for Nextcloud', 'es' => 'MCP for Nextcloud'], $this->localized('name'));
    }

    /** The description says what the app does, how it keeps the user in control and which clients connect to it. */
    public function testTheDescriptionListsTheModulesTheSafeguardsAndTheClients(): void {
        $expected = [
            'en' => ['Files', 'Notes', 'Calendar', 'Contacts', 'Tasks', 'Deck', 'Talk', 'Claude', 'ChatGPT', 'Gemini CLI', 'OAuth', 'never deletes'],
            'pt-br' => ['Arquivos', 'Notas', 'Calendário', 'Contatos', 'Tarefas', 'Deck', 'Talk', 'Claude', 'ChatGPT', 'Gemini CLI', 'OAuth', 'nunca apaga'],
            'es' => ['Archivos', 'Notas', 'Calendario', 'Contactos', 'Tareas', 'Deck', 'Talk', 'Claude', 'ChatGPT', 'Gemini CLI', 'OAuth', 'nunca borra'],
        ];
        foreach ($this->localized('description') as $lang => $text) {
            foreach ($expected[$lang] as $needle) {
                $this->assertStringContainsString($needle, $text, "description ($lang)");
            }
            $this->assertStringContainsString('31', $text, "description ($lang) names the supported Nextcloud versions");
        }
    }

    public function testStoreMetadataPointsAtThePublicRepository(): void {
        $manifest = $this->manifest();
        $repository = 'https://github.com/sintax-tech/mcp-for-nextcloud';

        $this->assertSame('mcp', (string)$manifest->id);
        $this->assertSame($repository, (string)$manifest->website);
        $this->assertSame($repository . '/issues', (string)$manifest->bugs);
        $this->assertSame($repository . '.git', (string)$manifest->repository);
        $this->assertSame('git', (string)$manifest->repository['type']);
        $this->assertSame('Jhonatan Jaworski', (string)$manifest->author);
        $this->assertSame('jhonatan@sintax.tech', (string)$manifest->author['mail']);
        $this->assertSame(['integration', 'tools'], array_map('strval', iterator_to_array($manifest->category, false)));
        $this->assertSame('AGPL-3.0-or-later', (string)$manifest->licence);
    }

    /** The two screenshots live in the repository's root screenshots/ folder on main, outside the app package, under the names agreed for the listing. */
    public function testScreenshotsComeFromTheRepository(): void {
        $base = 'https://raw.githubusercontent.com/sintax-tech/mcp-for-nextcloud/main/screenshots/';

        $this->assertSame([$base . 'admin.png', $base . 'plan.png'], array_map('strval', iterator_to_array($this->manifest()->screenshot, false)));
    }

    /**
     * Nextcloud 31 to 33; PHP from 8.2, the platform of composer.json and of the bundled vendor/. Nextcloud 31 and 32
     * also run on 8.1, which the app does not declare; the upper bound is the one each Nextcloud enforces itself.
     */
    public function testDependenciesDeclareNextcloud31To33AndPhp82(): void {
        $dependencies = $this->manifest()->dependencies;

        $this->assertSame(['31', '33'], [(string)$dependencies->nextcloud['min-version'], (string)$dependencies->nextcloud['max-version']]);
        $this->assertSame('8.2', (string)$dependencies->php['min-version']);
        $this->assertNull($dependencies->php['max-version']);
        $composer = json_decode((string)file_get_contents(dirname(self::MANIFEST, 2) . '/composer.json'), true);
        $this->assertSame('>=8.2', $composer['require']['php']);
        $this->assertSame('8.2', $composer['config']['platform']['php']);
    }

    /**
     * @param list<LibXMLError> $errors collected by libxml during the last step
     * @return string their messages, one per line, for the failure report
     */
    private function messages(array $errors): string {
        return trim(implode("\n", array_map(static fn (LibXMLError $error): string => trim($error->message), $errors)));
    }
}
