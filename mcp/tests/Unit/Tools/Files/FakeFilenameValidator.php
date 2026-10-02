<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCP\Files\EmptyFileNameException;
use OCP\Files\FileNameTooLongException;
use OCP\Files\IFilenameValidator;
use OCP\Files\InvalidCharacterInPathException;
use OCP\Files\InvalidDirectoryException;
use OCP\Files\InvalidPathException;
use OCP\Files\ReservedWordException;

/**
 * IFilenameValidator with the rules OC\Files\FilenameValidator of Nextcloud 33 applies on a default install:
 * ".htaccess" is a reserved name, ".filepart" and ".part" are forbidden extensions, "/" and "\" and control
 * characters are forbidden, "." and ".." are refused and a name has at most 250 bytes. Like the core, every
 * refusal is an InvalidPathException and its message names the offending value.
 */
final class FakeFilenameValidator implements IFilenameValidator {
    /** @var list<string> names Nextcloud refuses outright, lower case */
    public array $forbiddenNames = ['.htaccess'];
    /** @var list<string> endings Nextcloud refuses, lower case */
    public array $forbiddenExtensions = ['.filepart', '.part'];

    public function isFilenameValid(string $filename): bool {
        try {
            $this->validateFilename($filename);
        } catch (InvalidPathException) {
            return false;
        }
        return true;
    }

    public function validateFilename(string $filename): void {
        $trimmed = trim($filename);
        if ($trimmed === '') {
            throw new EmptyFileNameException();
        }
        if ($trimmed === '.' || $trimmed === '..') {
            throw new InvalidDirectoryException('Dot files are not allowed');
        }
        if (isset($filename[250])) {
            throw new FileNameTooLongException();
        }
        $lower = mb_strtolower($filename);
        if (in_array($lower, $this->forbiddenNames, true)) {
            throw new ReservedWordException('"' . $lower . '" is a forbidden file or folder name.');
        }
        foreach ($this->forbiddenExtensions as $extension) {
            if (str_ends_with($lower, $extension)) {
                throw new InvalidPathException('"' . $extension . '" is a forbidden file type in ' . $filename);
            }
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $filename) === 1 || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new InvalidCharacterInPathException('"' . $filename . '" has a forbidden character.');
        }
    }

    public function sanitizeFilename(string $name, ?string $charReplacement = null): string {
        return str_replace(['/', '\\'], $charReplacement ?? '_', $name);
    }
}
