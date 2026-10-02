<?php
declare(strict_types=1);

/**
 * Stubs of OCA\Files_Versions\Versions\*, the internal API Nextcloud 33 has no OCP for.
 *
 * Every signature here was read from stable33: getVersionsForFile, rollback and read in
 * apps/files_versions/lib/Versions/IVersionBackend.php, the accessors in IVersion.php. They are plain
 * classes (not PHPUnit doubles) because the unit tests load them with require_once, and they live in the
 * namespace the real classes use so the container double resolves them by the same string.
 */

namespace OCA\Files_Versions\Versions;

/** One stored version, as files_versions hands it out. */
final class FakeVersion {
    /** @var list<string> */
    public array $contents = [];

    /**
     * @param int|string $revision identifier the tools expose as a string
     * @param int $timestamp when the version was taken
     * @param int $size size in bytes
     * @param string $mime MIME type at that moment
     * @param string $name file name at that moment
     */
    public function __construct(
        private int|string $revision,
        private int $timestamp,
        private int $size,
        private string $mime,
        private string $name,
    ) {}

    /** @return int|string the revision identifier */
    public function getRevisionId(): int|string {
        return $this->revision;
    }

    /** @return int when the version was created */
    public function getTimestamp(): int {
        return $this->timestamp;
    }

    /** @return int the size in bytes */
    public function getSize(): int {
        return $this->size;
    }

    /** @return string the file name at that moment */
    public function getSourceFileName(): string {
        return $this->name;
    }

    /** @return string the MIME type at that moment */
    public function getMimeType(): string {
        return $this->mime;
    }

    /**
     * @param string $content what read() should hand back
     */
    public function withContent(string $content): self {
        $this->contents = [$content];
        return $this;
    }

    /** @return resource|false a readable stream, as IVersionManager::read() returns */
    public function read() {
        if ($this->contents === []) {
            return false;
        }
        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $this->contents[0]);
        rewind($handle);
        return $handle;
    }
}

/** Stand-in for OCA\Files_Versions\Versions\IVersionManager. */
final class FakeVersionManager {
    /** @var list<FakeVersion> versions handed out, newest first */
    public array $versions = [];
    /** @var list<string> operations in order, so a test can assert what ran */
    public array $ops = [];
    /** Value rollback() answers; null is what files_versions returns on success. */
    public bool|null $rollbackResult = null;
    /**
     * What the rollback does to the file, as the real one overwrites it with the version: a test puts the effect in
     * the tree so the order against the backup is observable on one timeline.
     *
     * @var (\Closure(FakeVersion): void)|null
     */
    public ?\Closure $onRollback = null;

    /**
     * @param list<FakeVersion> $versions versions of the file
     * @return self the manager, ready to be resolved by the container double
     */
    public static function with(array $versions): self {
        $manager = new self();
        $manager->versions = $versions;
        return $manager;
    }

    /**
     * @param \OCP\IUser $user owner of the versions
     * @param \OCP\Files\FileInfo $file file the versions belong to
     * @return list<FakeVersion> the versions
     */
    public function getVersionsForFile(\OCP\IUser $user, \OCP\Files\FileInfo $file): array {
        $this->ops[] = 'list';
        return $this->versions;
    }

    /**
     * @param FakeVersion $version version to roll back to
     * @return bool|null what the real manager reports
     */
    public function rollback(FakeVersion $version): bool|null {
        $this->ops[] = 'rollback';
        if ($this->onRollback !== null) {
            ($this->onRollback)($version);
        }
        return $this->rollbackResult;
    }

    /**
     * @param FakeVersion $version version to read
     * @return resource|false the content
     */
    public function read(FakeVersion $version) {
        $this->ops[] = 'read';
        return $version->read();
    }
}
