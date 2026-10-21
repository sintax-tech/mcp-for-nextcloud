<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Versions;

use OCA\Mcp\Modules\Files\Reading\OcrSupport;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Reading\TextExtractor;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\NotFoundExceptionInterface;

final class VersionReader {
    public const APP = 'files_versions';
    public const DEFAULT_LIMIT = 50;
    public const MAX_CHARS = 100000;
    public const MANAGER = 'OCA\Files_Versions\Versions\IVersionManager';

    public function __construct(
        private IAppManager $appManager,
        private IUserManager $userManager,
        private TextExtractor $extractor,
        private NodeAccessInfo $access,
        private \Closure $resolveManager,
        private OcrSupport $ocr,
    ) {}

    /**
     * @param Folder $root the user's folder
     * @param File $file file whose versions are listed
     * @param string $path normalized user-relative path of $file
     * @param int $limit maximum number of versions
     * @param string $viewerUid authenticated user
     * @return array{path:string, access:array<string, mixed>, versions:list<array{revision:string, timestamp:string, size:int, mime:string, name:string}>, truncated:bool}
     * @throws ToolFailure when versioning is off or unreadable
     */
    public function list(Folder $root, File $file, string $path, int $limit, string $viewerUid): array {
        $user = $this->requireVersioning($viewerUid);
        $versions = $this->manager()->getVersionsForFile($user, $file);
        $out = [];
        foreach (array_slice($versions, 0, $limit) as $version) {
            $out[] = [
                'revision' => (string)$version->getRevisionId(),
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $version->getTimestamp()),
                'size' => (int)$version->getSize(),
                'mime' => $version->getMimeType(),
                'name' => $version->getSourceFileName(),
            ];
        }
        return [
            'path' => $path,
            'access' => $this->access->describe($file, $viewerUid),
            'versions' => $out,
            'truncated' => count($versions) > count($out),
        ];
    }

    /**
     * @param File $file file the version belongs to
     * @param string $path normalized user-relative path of $file
     * @param string $revision version identifier as returned by list()
     * @param string $viewerUid authenticated user
     * @return array{path:string, version:string, size:int, mime:string, text:string, truncated:bool, text_layer?:bool, ocr_active?:bool, notice?:string}
     * @throws ToolFailure when versioning is off, the version does not exist or cannot be read
     */
    public function read(File $file, string $path, string $revision, string $viewerUid): array {
        $user = $this->requireVersioning($viewerUid);
        $version = $this->find($user, $file, $revision);
        $handle = $this->manager()->read($version);
        if (!is_resource($handle)) {
            throw new ToolFailure(VersionMessages::versionUnreadable());
        }
        try {
            $bytes = (string)stream_get_contents($handle, TextExtractor::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (strlen($bytes) > TextExtractor::MAX_BYTES) {
            throw new ToolFailure(ReadingMessages::readTooLarge(TextExtractor::MAX_BYTES));
        }
        $mime = $version->getMimeType();
        $name = $version->getSourceFileName() ?: $path;
        $scannable = OcrSupport::canLackText($name, $mime);
        $text = $scannable && str_starts_with(strtolower($mime), 'image/') ? '' : $this->extractor->extractBytes($bytes, $name, $mime);
        if ($scannable && trim($text) === '') {
            $notice = $this->ocr->noTextNotice();
            return ['path' => $path, 'version' => $revision, 'size' => strlen($bytes), 'mime' => $mime, 'text' => $notice,
                'truncated' => false, 'text_layer' => false, 'ocr_active' => $this->ocr->isActive(), 'notice' => $notice];
        }
        return [
            'path' => $path,
            'version' => $revision,
            'size' => strlen($bytes),
            'mime' => $mime,
            'text' => mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) . "\n\n" . ReadingMessages::textTruncated() : $text,
            'truncated' => mb_strlen($text) > self::MAX_CHARS,
        ];
    }

    /**
     * Describes the version a rollback would bring back, without reading its content and without writing.
     *
     * @param File $file file to be rolled back
     * @param string $revision version identifier as returned by list()
     * @param string $viewerUid authenticated user
     * @return array{revision:string, timestamp:string, size:int, mime:string, name:string}
     * @throws ToolFailure when versioning is off or no version carries that identifier
     */
    public function describe(File $file, string $revision, string $viewerUid): array {
        $version = $this->find($this->requireVersioning($viewerUid), $file, $revision);
        return [
            'revision' => (string)$version->getRevisionId(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $version->getTimestamp()),
            'size' => (int)$version->getSize(),
            'mime' => $version->getMimeType(),
            'name' => $version->getSourceFileName(),
        ];
    }

    /**
     * @param string $viewerUid authenticated user
     * @return IUser the user the versions belong to
     * @throws ToolFailure when files_versions is not enabled for them
     */
    public function requireVersioning(string $viewerUid): IUser {
        $user = $this->userManager->get($viewerUid);
        if ($user === null || !$this->appManager->isEnabledForUser(self::APP, $user)) {
            throw new ToolFailure(VersionMessages::versionsOff());
        }
        return $user;
    }

    /**
     * @param IUser $user owner of the versions
     * @param File $file file the version belongs to
     * @param string $revision version identifier
     * @return object the IVersion whose revision matches
     * @throws ToolFailure when no version carries that identifier
     */
    public function find(IUser $user, File $file, string $revision): object {
        foreach ($this->manager()->getVersionsForFile($user, $file) as $version) {
            if ((string)$version->getRevisionId() === $revision) {
                return $version;
            }
        }
        throw new ToolFailure(VersionMessages::versionMissing($revision));
    }

    /**
     * @return object the files_versions manager, resolved on first use
     * @throws ToolFailure when the app is enabled but its classes cannot be resolved
     */
    public function manager(): object {
        try {
            $manager = ($this->resolveManager)();
        } catch (NotFoundExceptionInterface | \RuntimeException) {
            $manager = null;
        }
        if (!is_object($manager)) {
            throw new ToolFailure(VersionMessages::versionsOff());
        }
        return $manager;
    }
}
