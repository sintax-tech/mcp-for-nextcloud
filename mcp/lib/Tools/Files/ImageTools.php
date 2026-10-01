<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IPreview;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only image tools: fetch previews as inline image bytes for the model, and search for images by
 * name, folder, modification date or system tag. Everything goes through the user's IRootFolder view
 * and native Nextcloud permissions, so a user never sees a file they could not reach in the Files app.
 */
final class ImageTools {
    /** Default max_size (in pixels) for a single image preview. */
    public const DEFAULT_MAX_SIZE = 1568;
    /** Lower bound of max_size. */
    public const MIN_MAX_SIZE = 256;
    /** Upper bound of max_size. */
    public const MAX_MAX_SIZE = 2048;
    /** App config key for the per-image byte limit. */
    public const SINGLE_MAX_BYTES_KEY = 'image_single_max_bytes';
    /** App config key for the batch total byte budget. */
    public const BATCH_MAX_BYTES_KEY = 'image_batch_max_bytes';
    /** Default per-image byte limit. */
    public const DEFAULT_SINGLE_MAX_BYTES = 1 * 1024 * 1024;
    /** Default total byte budget for files_images_view. */
    public const DEFAULT_BATCH_MAX_BYTES = 4 * 1024 * 1024;
    /** Maximum number of images requested in a batch call. */
    public const BATCH_MAX_ITEMS = 6;
    /** Default limit of files_image_search. */
    public const SEARCH_DEFAULT_LIMIT = 25;
    /** Hard ceiling for files_image_search. */
    public const SEARCH_MAX_LIMIT = 100;
    /** Overfetch factor to make up for files filtered out after search. */
    public const SEARCH_OVERFETCH = 4;
    /** MIME types that are safe to return as-is when no preview provider accepts the file. */
    private const RETURNABLE_ORIGINAL_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    /** Metadata key published by the Files Metadata app for image dimensions [width, height]. */
    private const METADATA_DIMENSIONS = 'photos-size';
    /** Metadata key published by the Files Metadata app for the original EXIF date. */
    private const METADATA_ORIGINAL_DATE = 'photos-original_date_time';
    /** Service id of the Files Metadata manager; resolved lazily so the app loads without it. */
    private const METADATA_SERVICE = 'OCP\\FilesMetadata\\IFilesMetadataManager';
    /** Object type used by the Tags app for files. */
    private const TAG_OBJECT_TYPE = 'files';

    public function __construct(
        private IPreview $preview,
        private NodeAccessInfo $accessInfo,
        private IConfig $config,
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private IUserManager $userManager,
        private LoggerInterface $logger,
        private ContainerInterface $container,
    ) {}

    /**
     * One image. The content array holds the image block, followed by a text block with metadata.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path user-relative path
     * @param int $maxSize max dimension in pixels
     * @return array{content: list<array<string, mixed>>}
     * @throws ToolFailure for a non-image, missing provider or budget exhaustion
     */
    public function view(Folder $root, string $userId, string $path, int $maxSize): array {
        $normalized = PathGuard::normalize($path);
        $file = $this->requireImage($root, $normalized);
        [$bytes, $mime, $effectiveSize] = $this->renderPreview($file, $maxSize, $this->singleMaxBytes());
        return ToolResult::image($bytes, $mime, $this->imageMetadata($file, $normalized, $userId, $effectiveSize));
    }

    /**
     * Several images, under a shared byte budget. Pass either paths (up to BATCH_MAX_ITEMS) or folder
     * (plus optional limit) to pick the most recently modified images under that folder. Skipped items
     * are reported in the trailing summary.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param list<string>|null $paths explicit paths, or null to pick from $folder
     * @param string|null $folder folder path when $paths is null
     * @param int $limit maximum items when picking from $folder
     * @param int $maxSize max dimension in pixels per image
     * @return array{content: list<array<string, mixed>>}
     * @throws ToolFailure when neither or both inputs are provided, or when the folder is unreachable
     */
    public function viewMany(Folder $root, string $userId, ?array $paths, ?string $folder, int $limit, int $maxSize): array {
        if ($paths === null && $folder === null) {
            throw new ToolFailure(FilesMessages::imagesNoTarget());
        }
        if ($paths !== null && $folder !== null) {
            throw new ToolFailure(FilesMessages::imagesConflict());
        }
        $selected = $paths !== null
            ? $this->selectPaths($root, $paths)
            : $this->selectFromFolder($root, (string)$folder, $limit);
        $budget = $this->batchMaxBytes();
        $items = [];
        $skipped = [];
        $used = 0;
        foreach ($selected as $entry) {
            [$path, $file, $error] = $entry;
            if ($error !== null) {
                $skipped[] = ['path' => $path, 'reason' => $error];
                continue;
            }
            $remaining = $budget - $used;
            if ($remaining <= 0) {
                $skipped[] = ['path' => $path, 'reason' => 'batch budget exhausted'];
                continue;
            }
            try {
                [$bytes, $mime, $effective] = $this->renderPreview($file, $maxSize, min($this->singleMaxBytes(), $remaining));
            } catch (ToolFailure $e) {
                $skipped[] = ['path' => $path, 'reason' => $e->getMessage()];
                continue;
            }
            $used += strlen($bytes);
            $items[] = [
                'bytes' => $bytes,
                'mimeType' => $mime,
                'metadata' => $this->imageMetadata($file, $path, $userId, $effective),
            ];
        }
        $summary = [
            'returned' => count($items),
            'skipped' => $skipped,
            'bytes_used' => $used,
            'bytes_budget' => $budget,
        ];
        return ToolResult::images($items, $summary);
    }

    /**
     * Search images only; filter by name, folder, mtime range and system tag. Returns metadata only;
     * use view/viewMany to fetch bytes.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param array{query?:string, folder?:string, modified_after?:string, modified_before?:string, tag?:string, limit:int} $args
     * @return list<array<string, mixed>>
     * @throws ToolFailure for an invalid folder or date
     */
    public function search(Folder $root, string $userId, array $args): array {
        $limit = (int)$args['limit'];
        $query = isset($args['query']) ? (string)$args['query'] : '';
        $folder = isset($args['folder']) ? PathGuard::normalize((string)$args['folder']) : '/';
        $after = $this->parseDate($args['modified_after'] ?? null);
        $before = $this->parseDate($args['modified_before'] ?? null);
        $tagObjectIds = isset($args['tag']) ? $this->objectIdsForTag((string)$args['tag']) : null;
        if ($tagObjectIds === []) {
            return [];
        }
        $scope = NodeAccess::get($root, $folder);
        if (!$scope instanceof Folder) {
            throw new ToolFailure(FilesMessages::notAFolder());
        }
        $operation = new MimeLikeComparison('image/%');
        $searchLimit = max($limit * self::SEARCH_OVERFETCH, $limit + 20);
        $search = new NameSearchQuery($operation, $searchLimit, $this->userManager->get($userId));
        $results = [];
        foreach ($scope->search($search) as $node) {
            if (!$node instanceof File || !$node->isReadable()) {
                continue;
            }
            $mtime = (int)$node->getMTime();
            if ($after !== null && $mtime < $after) {
                continue;
            }
            if ($before !== null && $mtime > $before) {
                continue;
            }
            if ($query !== '' && stripos($node->getName(), $query) === false) {
                continue;
            }
            if ($tagObjectIds !== null && !in_array((string)$node->getId(), $tagObjectIds, true)) {
                continue;
            }
            $results[] = $node;
        }
        usort($results, static fn (File $a, File $b): int => (int)$b->getMTime() <=> (int)$a->getMTime());
        $results = array_slice($results, 0, $limit);
        return array_map(fn (File $file) => $this->searchEntry($root, $userId, $file), $results);
    }

    /**
     * Picks images under a folder, newest first.
     *
     * @param Folder $root the user's folder
     * @param string $folder user-relative folder path
     * @param int $limit maximum items to pick
     * @return list<array{0:string, 1:File|null, 2:string|null}> path, file, error (one of file/error is set)
     * @throws ToolFailure when the folder path is not a folder
     */
    private function selectFromFolder(Folder $root, string $folder, int $limit): array {
        $normalized = PathGuard::normalize($folder);
        $scope = NodeAccess::get($root, $normalized);
        if (!$scope instanceof Folder) {
            throw new ToolFailure(FilesMessages::notAFolder());
        }
        $operation = new MimeLikeComparison('image/%');
        $search = new NameSearchQuery($operation, max($limit * self::SEARCH_OVERFETCH, $limit + 10), $this->userManager->get((string)$scope->getOwner()?->getUID()));
        $files = [];
        foreach ($scope->search($search) as $node) {
            if ($node instanceof File && $node->isReadable()) {
                $files[] = $node;
            }
        }
        usort($files, static fn (File $a, File $b): int => (int)$b->getMTime() <=> (int)$a->getMTime());
        $files = array_slice($files, 0, min($limit, self::BATCH_MAX_ITEMS));
        return array_map(fn (File $f) => [(string)$root->getRelativePath($f->getPath()), $f, null], $files);
    }

    /**
     * Looks up explicit paths and marks the ones that are not readable images.
     *
     * @param Folder $root the user's folder
     * @param list<string> $paths user-relative paths
     * @return list<array{0:string, 1:File|null, 2:string|null}> path, file, error
     */
    private function selectPaths(Folder $root, array $paths): array {
        $out = [];
        foreach ($paths as $raw) {
            $path = PathGuard::normalize((string)$raw);
            try {
                $node = NodeAccess::get($root, $path);
                if (!$node instanceof File) {
                    $out[] = [$path, null, FilesMessages::notAnImage()];
                    continue;
                }
                if (!self::looksLikeImage((string)$node->getMimetype())) {
                    $out[] = [$path, null, FilesMessages::notAnImage()];
                    continue;
                }
                $out[] = [$path, $node, null];
            } catch (NotFoundException) {
                $out[] = [$path, null, 'not found'];
            } catch (ToolFailure $e) {
                $out[] = [$path, null, $e->getMessage()];
            }
        }
        return $out;
    }

    /**
     * Renders a preview, shrinking max_size until the bytes fit the per-image limit.
     *
     * @param File $file source image
     * @param int $maxSize initial max dimension in pixels
     * @param int $byteLimit per-image byte limit
     * @return array{0:string, 1:string, 2:int} bytes, mimeType, effective max_size used
     * @throws ToolFailure when no preview can be produced and the original cannot be returned
     */
    private function renderPreview(File $file, int $maxSize, int $byteLimit): array {
        $current = $maxSize;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $simple = $this->preview->getPreview($file, $current, $current, false);
            } catch (\Throwable $e) {
                $this->logger->debug('MCP image preview failed', ['app' => 'mcp', 'exception_class' => $e::class]);
                break;
            }
            $bytes = $simple->getContent();
            if (strlen($bytes) <= $byteLimit) {
                return [$bytes, $simple->getMimeType(), $current];
            }
            $current = max(self::MIN_MAX_SIZE, (int)floor($current / 2));
            if ($current === self::MIN_MAX_SIZE && strlen($bytes) <= $byteLimit) {
                return [$bytes, $simple->getMimeType(), $current];
            }
        }
        // Preview missing or still too big: fall back to the original when it is a safe raster format
        // under the byte limit; otherwise the file cannot be shown.
        $mime = strtolower((string)$file->getMimetype());
        if (in_array($mime, self::RETURNABLE_ORIGINAL_MIMES, true)) {
            $size = (int)$file->getSize();
            if ($size > 0 && $size <= $byteLimit) {
                return [(string)$file->getContent(), $mime, $maxSize];
            }
            throw new ToolFailure(FilesMessages::imagePreviewTooLarge());
        }
        throw new ToolFailure(FilesMessages::imageUnsupported());
    }

    /**
     * @param string $tagName case-sensitive tag name
     * @return list<string> object ids with the tag, or [] when the tag does not exist
     */
    private function objectIdsForTag(string $tagName): array {
        try {
            // user-visible or restricted tags (true/false) are both valid; Recognize creates visible ones.
            $tag = $this->findTag($tagName);
        } catch (TagNotFoundException) {
            return [];
        }
        if ($tag === null) {
            return [];
        }
        try {
            return $this->tagMapper->getObjectIdsForTags($tag->getId(), self::TAG_OBJECT_TYPE);
        } catch (TagNotFoundException) {
            return [];
        }
    }

    /** Searches for a tag by name across visibility combinations; returns null when nothing matched. */
    private function findTag(string $tagName): ?\OCP\SystemTag\ISystemTag {
        foreach ($this->tagManager->getAllTags(null, $tagName) as $tag) {
            if ($tag->getName() === $tagName) {
                return $tag;
            }
        }
        return null;
    }

    /**
     * Original image dimensions [width, height], either from Files Metadata ('photos-size')
     * or by inspecting the image stream header.
     *
     * @return array{width: int, height: int}|null
     */
    private function originalDimensions(File $file): ?array {
        try {
            $manager = $this->container->get(self::METADATA_SERVICE);
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
    private function imageMetadata(File $file, string $path, string $userId, int $effectiveMaxSize): array {
        $meta = [
            'path' => $path,
            'name' => $file->getName(),
            'mime' => (string)$file->getMimetype(),
            'size' => (int)$file->getSize(),
            'mtime' => gmdate('D, d M Y H:i:s \\G\\M\\T', (int)$file->getMTime()),
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
    private function searchEntry(Folder $root, string $userId, File $file): array {
        $entry = [
            'path' => (string)$root->getRelativePath($file->getPath()),
            'name' => $file->getName(),
            'mime' => (string)$file->getMimetype(),
            'size' => (int)$file->getSize(),
            'mtime' => gmdate('D, d M Y H:i:s \\G\\M\\T', (int)$file->getMTime()),
            'tags' => $this->tagsOf((int)$file->getId()),
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
            $manager = $this->container->get(self::METADATA_SERVICE);
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
     * @return list<string> tag names visible to the viewer; empty on missing tag app or metadata errors
     */
    private function tagsOf(int $fileId): array {
        try {
            $byObject = $this->tagMapper->getTagIdsForObjects([(string)$fileId], self::TAG_OBJECT_TYPE);
            $tagIds = $byObject[(string)$fileId] ?? [];
            if ($tagIds === []) {
                return [];
            }
            $tags = $this->tagManager->getTagsByIds($tagIds);
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

    /**
     * @param Folder $root the user's folder
     * @param string $path user-relative path
     * @return File readable image file
     * @throws ToolFailure for a folder, a non-image or an unreadable node
     */
    private function requireImage(Folder $root, string $path): File {
        $node = NodeAccess::get($root, $path);
        if (!$node instanceof File) {
            throw new ToolFailure(FilesMessages::notAnImage());
        }
        if (!self::looksLikeImage((string)$node->getMimetype())) {
            throw new ToolFailure(FilesMessages::notAnImage());
        }
        return $node;
    }

    /** @return bool whether the mimetype belongs to the image/* family or is a PDF (previews only). */
    private static function looksLikeImage(string $mime): bool {
        $mime = strtolower($mime);
        return str_starts_with($mime, 'image/') || $mime === 'application/pdf';
    }

    /** @param mixed $raw ISO 8601 string or null
     * @return int|null epoch seconds, or null when $raw is null
     * @throws ToolFailure when $raw is set but not a parseable ISO 8601 moment
     */
    private function parseDate(mixed $raw): ?int {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = (string)$raw;
        $time = strtotime($value);
        if ($time === false) {
            throw new ToolFailure(FilesMessages::invalidDate($value));
        }
        return $time;
    }

    /** @return int per-image byte limit, from app config with a sane default and a lower floor */
    private function singleMaxBytes(): int {
        $configured = (int)$this->config->getAppValue('mcp', self::SINGLE_MAX_BYTES_KEY, (string)self::DEFAULT_SINGLE_MAX_BYTES);
        return $configured > 0 ? $configured : self::DEFAULT_SINGLE_MAX_BYTES;
    }

    /** @return int batch budget, from app config with a sane default */
    private function batchMaxBytes(): int {
        $configured = (int)$this->config->getAppValue('mcp', self::BATCH_MAX_BYTES_KEY, (string)self::DEFAULT_BATCH_MAX_BYTES);
        return $configured > 0 ? $configured : self::DEFAULT_BATCH_MAX_BYTES;
    }
}
