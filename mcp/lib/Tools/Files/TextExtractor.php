<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;
use OCP\ITempManager;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/** Reads a file within a byte limit and turns text, PDF, DOCX and ODT into plain text. */
class TextExtractor {
    /** Largest file read (and largest zip entry inflated), in bytes. */
    public const MAX_BYTES = 20 * 1024 * 1024;
    /** Characters returned by files_read and resources/read before the text is truncated. */
    public const MAX_CHARS = 100_000;
    /** Extensions returned as UTF-8 text. */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'yaml', 'yml', 'log', 'ini', 'conf', 'html', 'htm', 'css', 'js', 'ts', 'php', 'py', 'sh', 'sql', 'svg', 'ics', 'vcf', 'rtf', 'tex'];
    /** Non text/* MIME types that are still text. */
    private const TEXT_MIMES = ['application/json', 'application/xml', 'application/x-yaml', 'application/yaml', 'application/javascript', 'application/x-php', 'application/sql', 'image/svg+xml'];
    /** MIME type of Word documents. */
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    /** MIME type of OpenDocument text. */
    private const ODT = 'application/vnd.oasis.opendocument.text';

    public function __construct(private ITempManager $tempManager) {}

    /**
     * @param File $file file whose MIME type and extension are checked
     * @return bool whether the file is plain text (and therefore readable as-is and editable)
     */
    public static function isText(File $file): bool {
        return self::isTextNamed($file->getName(), (string)$file->getMimetype());
    }

    /**
     * @param string $name file name, whose extension counts as text
     * @param string $mime MIME type of the file
     * @return bool whether these two describe plain text, for a node or for a stored version alike
     */
    public static function isTextNamed(string $name, string $mime): bool {
        $mime = strtolower($mime);
        return str_starts_with($mime, 'text/') || in_array($mime, self::TEXT_MIMES, true)
            || in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true);
    }

    /**
     * @param string $name file name
     * @param string $mime MIME type of the file
     * @return bool whether the file can be extracted into text (plain text, PDF, DOCX, ODT)
     */
    public static function canExtract(string $name, string $mime): bool {
        $mime = strtolower($mime);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $mime === 'application/pdf' || $extension === 'pdf'
            || $mime === self::DOCX || $extension === 'docx'
            || $mime === self::ODT || $extension === 'odt'
            || self::isTextNamed($name, $mime);
    }

    /**
     * @param string $name file name
     * @param string $mime original file MIME type
     * @return string MIME type of the extracted content (text/plain for converted documents, original for text/*)
     */
    public static function textMime(string $name, string $mime): string {
        $lowerMime = strtolower($mime);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($lowerMime === 'application/pdf' || $extension === 'pdf'
            || $lowerMime === self::DOCX || $extension === 'docx'
            || $lowerMime === self::ODT || $extension === 'odt') {
            return 'text/plain';
        }
        return $mime !== '' ? $mime : 'text/plain';
    }

    /**
     * Extracts text from a file, enforcing the byte limit and truncating to MAX_CHARS.
     * Shared by files_read and resources/read.
     *
     * @param File $file readable file within MAX_BYTES
     * @param int $maxChars character limit before truncation
     * @return string extracted and truncated text
     * @throws ToolFailure over the byte limit, unreadable or unsupported format
     */
    public function readText(File $file, int $maxChars = self::MAX_CHARS): string {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ToolFailure(self::tooLarge());
        }
        $text = $this->extract($file);
        return mb_strlen($text) > $maxChars
            ? mb_substr($text, 0, $maxChars) . "\n\n" . FilesMessages::textTruncated()
            : $text;
    }

    /**
     * @param File $file readable file within the byte limit
     * @return string extracted text; a localized extraction-failure notice (see FilesMessages::notExtracted) for corrupt PDF/DOCX/ODT
     * @throws ToolFailure over the byte limit, unreadable or unsupported format
     */
    public function extract(File $file): string {
        return $this->extractBytes($this->read($file), $file->getName(), (string)$file->getMimetype());
    }

    /**
     * Same extraction for content that is not a node, so a stored version reads exactly like a file.
     *
     * @param string $bytes raw content within MAX_BYTES
     * @param string $name file name the content came from, whose extension counts as text
     * @param string $mime MIME type the content came from
     * @return string extracted text; a localized extraction-failure notice (see FilesMessages::notExtracted) for a corrupt PDF/DOCX/ODT
     * @throws ToolFailure for an unsupported format
     */
    public function extractBytes(string $bytes, string $name, string $mime): string {
        $mime = strtolower($mime);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($mime === 'application/pdf' || $extension === 'pdf') {
            return $this->guarded($name, fn () => $this->pdf($bytes));
        }
        if ($mime === self::DOCX || $extension === 'docx') {
            return $this->guarded($name, fn () => $this->zipXml($bytes, 'word/document.xml', ['#</w:p>#' => "\n", '#<w:tab\s*/>#' => "\t", '#<w:(br|cr)\b[^>]*/>#' => "\n"]));
        }
        if ($mime === self::ODT || $extension === 'odt') {
            return $this->guarded($name, fn () => $this->zipXml($bytes, 'content.xml', ['#</text:(p|h)>#' => "\n", '#<text:tab\s*/>#' => "\t", '#<text:line-break\s*/>#' => "\n", '#<text:s\s*/>#' => ' ']));
        }
        if (self::isTextNamed($name, $mime)) {
            $text = mb_scrub($bytes, 'UTF-8');
            return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
        }
        throw new ToolFailure(FilesMessages::unsupportedFormat());
    }

    /**
     * Refuses before opening when the size is known, and never buffers more than the limit + 1 byte.
     *
     * @param File $file file to read
     * @return string raw bytes
     * @throws ToolFailure over the byte limit or when the stream cannot be read
     */
    public function read(File $file): string {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ToolFailure(self::tooLarge());
        }
        $handle = $file->fopen('r');
        if ($handle === false) {
            throw new ToolFailure(FilesMessages::openFailed());
        }
        try {
            $bytes = stream_get_contents($handle, self::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if ($bytes === false) {
            throw new ToolFailure(FilesMessages::readFailed());
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ToolFailure(self::tooLarge());
        }
        return $bytes;
    }

    /** @return string client message for a file over MAX_BYTES */
    public static function tooLarge(): string {
        return FilesMessages::readTooLarge(self::MAX_BYTES);
    }

    /**
     * @param string $name file name named in the failure notice
     * @param callable():string $extract parser call whose failures become a generic notice
     * @return string the extracted text or the notice
     */
    private function guarded(string $name, callable $extract): string {
        try {
            return trim($extract());
        } catch (\Throwable) {
            return FilesMessages::notExtracted($name);
        }
    }

    /**
     * Extracts the text of a PDF with smalot/pdfparser. Embedded images are not retained and the decode
     * memory is capped, so a hostile or huge file cannot exhaust PHP memory.
     *
     * @param string $bytes raw PDF content, already within MAX_BYTES
     * @return string plain text of all pages
     * @throws \Exception when the PDF cannot be parsed
     */
    private function pdf(string $bytes): string {
        $config = new Config();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(self::MAX_BYTES * 4);
        return (new Parser([], $config))->parseContent($bytes)->getText();
    }

    /**
     * @param string $entry XML entry holding the document body
     * @param array<string, string> $breaks regex => replacement applied before stripping tags
     * @throws \RuntimeException when the archive or entry is missing, unreadable or too large
     */
    private function zipXml(string $bytes, string $entry, array $breaks): string {
        $path = $this->tempManager->getTemporaryFile('.zip');
        if ($path === false) {
            throw new \RuntimeException('no temp file');
        }
        try {
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                throw new \RuntimeException('not a zip');
            }
            try {
                $stat = $zip->statName($entry);
                // The declared size guards against zip bombs; the read length caps a lying header.
                if ($stat === false || $stat['size'] > self::MAX_BYTES) {
                    throw new \RuntimeException('entry missing or too large');
                }
                $xml = $zip->getFromName($entry, self::MAX_BYTES);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
        if (!is_string($xml)) {
            throw new \RuntimeException('unreadable entry');
        }
        $text = strip_tags((string)preg_replace(array_keys($breaks), array_values($breaks), $xml));
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
