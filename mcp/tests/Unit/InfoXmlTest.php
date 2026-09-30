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

    /**
     * @param list<LibXMLError> $errors collected by libxml during the last step
     * @return string their messages, one per line, for the failure report
     */
    private function messages(array $errors): string {
        return trim(implode("\n", array_map(static fn (LibXMLError $error): string => trim($error->message), $errors)));
    }
}
