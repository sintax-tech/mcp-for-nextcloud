<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Container\NotFoundExceptionInterface;

final class ImageMetadata {
    public const METADATA_DIMENSIONS = 'photos-size';
    public const METADATA_ORIGINAL_DATE = 'photos-original_date_time';
    public const METADATA_SERVICE = 'OCP\\FilesMetadata\\IFilesMetadataManager';
    public const TAG_OBJECT_TYPE = 'files';

    public function __construct(
        private NodeAccessInfo $accessInfo,
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private IUserManager $userManager,
        private \Closure $resolveMetadata,
    ) {}

    /**
     * Original image dimensions [width, height], either from Files Metadata ('photos-size')
     * or by inspecting the image stream header.
     *
     * @return array{width: int, height: int}|null
     */
    private function originalDimensions(File $file): ?array {
        try {
            $manager = ($this->resolveMetadata)();
            if ($manager !== null) {
                $metadata = $manager->getMetadata((int)$file->getId(), false);
                if ($metadata->hasKey(self::METADATA_DIMENSIONS)) {
                    $pair = $metadata->getArray(self::METADATA_DIMENSIONS);
                    if (isset($pair[0], $pair[1]) && (int)$pair[0] > 0 && (int)$pair[1] > 0) {
                        return ['width' => (int)$pair[0], 'height' => (int)$pair[1]];
                    }
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            $stream = $file->fopen('rb');
            if (is_resource($stream)) {
                $header = (string)fread($stream, 65536);
                fclose($stream);
                $info = @getimagesizefromstring($header);
                if (is_array($info) && isset($info[0], $info[1]) && (int)$info[0] > 0 && (int)$info[1] > 0) {
                    return ['width' => (int)$info[0], 'height' => (int)$info[1]];
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param File $file image
     * @param string $path user-relative path
     * @param string $userId authenticated user
     * @param int $effectiveMaxSize max_size that produced the returned bytes
     * @return array<string, mixed> metadata block for the image
     */
    public function imageMetadata(File $file, string $path, string $userId, int $effectiveMaxSize): array {
        $meta = [
            'path' => $path,
            'name' => $file->getName(),
            'mime' => (string)$file->getMimetype(),
            'size' => (int)$file->getSize(),
            'mtime' => gmdate('Y-m-d\TH:i:s\Z', (int)$file->getMTime()),
            'effective_max_size' => $effectiveMaxSize,
            'access' => $this->accessInfo->describe($file, $userId),
        ];
        $dimensions = $this->originalDimensions($file);
        if ($dimensions !== null) {
            $meta['dimensions'] = $dimensions;
        }
        $captured = $this->originalCaptureTime($file);
        if ($captured !== null) {
            $meta['captured_at'] = $captured;
        }
        return $meta;
    }

    /**
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param File $file image found by search
     * @return array<string, mixed> search entry
     */
    public function searchEntry(Folder $root, string $userId, File $file): array {
        $entry = [
            'path' => (string)$root->getRelativePath($file->getPath()),
            'name' => $file->getName(),
            'mime' => (string)$file->getMimetype(),
            'size' => (int)$file->getSize(),
            'mtime' => gmdate('Y-m-d\TH:i:s\Z', (int)$file->getMTime()),
            'tags' => $this->tagsOf((int)$file->getId(), $userId),
            'access' => $this->accessInfo->describe($file, $userId),
        ];
        $captured = $this->originalCaptureTime($file);
        if ($captured !== null) {
            $entry['captured_at'] = $captured;
        }
        return $entry;
    }

    /**
     * EXIF capture date, when the Files Metadata app recorded one. Missing values are not an error.
     *
     * @param File $file image file
     * @return string|null ISO 8601 capture time, or null when absent
     */
    private function originalCaptureTime(File $file): ?string {
        try {
            $manager = ($this->resolveMetadata)();
        } catch (NotFoundExceptionInterface|\Throwable) {
            return null;
        }
        try {
            $metadata = $manager->getMetadata((int)$file->getId(), false);
            if (!$metadata->hasKey(self::METADATA_ORIGINAL_DATE)) {
                return null;
            }
            $value = $metadata->getString(self::METADATA_ORIGINAL_DATE);
            return $value === '' ? null : $value;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param int $fileId file id
     * @param string|null $userId user to run visibility checks for
     * @return list<string> tag names visible to the viewer; empty on missing tag app or metadata errors
     */
    private function tagsOf(int $fileId, ?string $userId = null): array {
        try {
            $byObject = $this->tagMapper->getTagIdsForObjects([(string)$fileId], self::TAG_OBJECT_TYPE);
            $tagIds = $byObject[(string)$fileId] ?? [];
            if ($tagIds === []) {
                return [];
            }
            $user = $userId !== null ? $this->userManager->get($userId) : null;
            $tags = $this->tagManager->getTagsByIds($tagIds, $user);
            $names = [];
            foreach ($tags as $tag) {
                if ($tag->isUserVisible()) {
                    $names[] = $tag->getName();
                }
            }
            sort($names);
            return $names;
        } catch (\Throwable) {
            return [];
        }
    }
}
