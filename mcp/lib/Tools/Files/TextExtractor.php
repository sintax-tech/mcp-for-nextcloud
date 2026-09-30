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
    public const MAX_BYTES = 20 * 1024 * 1024;
    private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'yaml', 'yml', 'log', 'ini', 'conf', 'html', 'htm', 'css', 'js', 'ts', 'php', 'py', 'sh', 'sql', 'svg', 'ics', 'vcf', 'rtf', 'tex'];
    private const TEXT_MIMES = ['application/json', 'application/xml', 'application/x-yaml', 'application/yaml', 'application/javascript', 'application/x-php', 'application/sql', 'image/svg+xml'];
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const ODT = 'application/vnd.oasis.opendocument.text';

    public function __construct(private ITempManager $tempManager) {}

    public static function isText(File $file): bool {
        $mime = strtolower((string)$file->getMimetype());
        return str_starts_with($mime, 'text/') || in_array($mime, self::TEXT_MIMES, true)
            || in_array(strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true);
    }

    public function extract(File $file): string {
        $bytes = $this->read($file);
        $mime = strtolower((string)$file->getMimetype());
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        if ($mime === 'application/pdf' || $extension === 'pdf') {
            return $this->guarded($file, fn () => $this->pdf($bytes));
        }
        if ($mime === self::DOCX || $extension === 'docx') {
            return $this->guarded($file, fn () => $this->zipXml($bytes, 'word/document.xml', ['#</w:p>#' => "\n", '#<w:tab\s*/>#' => "\t", '#<w:(br|cr)\b[^>]*/>#' => "\n"]));
        }
        if ($mime === self::ODT || $extension === 'odt') {
            return $this->guarded($file, fn () => $this->zipXml($bytes, 'content.xml', ['#</text:(p|h)>#' => "\n", '#<text:tab\s*/>#' => "\t", '#<text:line-break\s*/>#' => "\n", '#<text:s\s*/>#' => ' ']));
        }
        if (self::isText($file)) {
            $text = mb_scrub($bytes, 'UTF-8');
            return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
        }
        throw new ToolFailure('Formato de arquivo não suportado para leitura de texto.');
    }

    /** Refuses before opening when the size is known, and never buffers more than the limit + 1 byte. */
    public function read(File $file): string {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ToolFailure(self::tooLarge());
        }
        $handle = $file->fopen('r');
        if ($handle === false) {
            throw new ToolFailure('Não foi possível abrir o arquivo.');
        }
        try {
            $bytes = stream_get_contents($handle, self::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if ($bytes === false) {
            throw new ToolFailure('Não foi possível ler o arquivo.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ToolFailure(self::tooLarge());
        }
        return $bytes;
    }

    public static function tooLarge(): string {
        return 'Arquivo excede o limite de leitura de ' . self::MAX_BYTES . ' bytes.';
    }

    private function guarded(File $file, callable $extract): string {
        try {
            return trim($extract());
        } catch (\Throwable) {
            return '[não foi possível extrair o texto de ' . $file->getName() . ']';
        }
    }

    private function pdf(string $bytes): string {
        $config = new Config();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(self::MAX_BYTES * 4);
        return (new Parser([], $config))->parseContent($bytes)->getText();
    }

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
