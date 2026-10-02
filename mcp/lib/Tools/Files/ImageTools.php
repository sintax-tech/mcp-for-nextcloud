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
use OCP\Files\NotFoundException;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only image retrieval, batching, previews and filtered search.
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
    /** Hard ceiling on object IDs resolved for a system tag search. */
    public const TAG_RESOLUTION_CEILING = 2000;
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
        private IDBConnection $db,
        private ?\OCA\Mcp\Service\VisibilityGuard $visibilityGuard = null,
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
            : $this->selectFromFolder($root, $userId, (string)$folder, $limit);
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
        $limit = max(1, min(self::SEARCH_MAX_LIMIT, (int)$args['limit']));
        $query = isset($args['query']) ? trim((string)$args['query']) : '';
        $folder = isset($args['folder']) ? PathGuard::normalize((string)$args['folder'], 'folder') : '/';
        $after = $this->parseDate($args['modified_after'] ?? null);
        $before = $this->parseDate($args['modified_before'] ?? null);
        $tag = isset($args['tag']) && trim((string)$args['tag']) !== '' ? trim((string)$args['tag']) : null;

        // A hidden folder answers like a missing one: an empty list would tell that it is there.
        $scope = NodeAccess::get($root, $folder, $this->visibilityGuard);
        if (!$scope instanceof Folder) {
            throw new ToolFailure(FilesMessages::notAFolder());
        }

        if ($tag !== null) {
            return $this->searchByTag($root, $scope, $userId, $tag, $query, $after, $before, $limit);
        }

        return $this->searchByQuery($root, $scope, $userId, $query, $after, $before, $limit);
    }

    /**
     * Resolves object IDs for a system tag, enforces user visibility and folder scoping, and sorts by mtime DESC.
     *
     * @return list<array<string, mixed>>
     */
    private function searchByTag(
        Folder $root,
        Folder $scope,
        string $userId,
        string $tagName,
        string $query,
        ?int $after,
        ?int $before,
        int $limit,
    ): array {
        $tag = $this->findTag($tagName);
        if ($tag === null) {
            return [];
        }

        try {
            $rawIds = $this->tagMapper->getObjectIdsForTags($tag->getId(), self::TAG_OBJECT_TYPE);
        } catch (TagNotFoundException|\Throwable) {
            return [];
        }

        if (!is_array($rawIds) || $rawIds === []) {
            return [];
        }

        $count = count($rawIds);
        if ($count > self::TAG_RESOLUTION_CEILING) {
            $this->logger->warning('Image tag search truncated: tag has more objects than ceiling', [
                'tag' => $tagName,
                'count' => $count,
                'ceiling' => self::TAG_RESOLUTION_CEILING,
            ]);
            $rawIds = array_slice($rawIds, 0, self::TAG_RESOLUTION_CEILING);
        }

        $files = [];
        foreach ($rawIds as $id) {
            try {
                $nodes = $scope->getById((int)$id);
            } catch (\Throwable) {
                continue;
            }
            foreach ($nodes as $node) {
                if (!$node instanceof File || !$node->isReadable()) {
                    continue;
                }
                if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
                    continue;
                }
                $mime = (string)$node->getMimetype();
                if (!self::looksLikeImage($mime)) {
                    continue;
                }
                if ($query !== '' && stripos($node->getName(), $query) === false) {
                    continue;
                }
                $mtime = (int)$node->getMTime();
                if ($after !== null && $mtime < $after) {
                    continue;
                }
                if ($before !== null && $mtime > $before) {
                    continue;
                }
                $files[] = $node;
            }
        }

        usort($files, static fn (File $a, File $b): int => (int)$b->getMTime() <=> (int)$a->getMTime());
        $files = array_slice($files, 0, $limit);

        return array_map(fn (File $file) => $this->searchEntry($root, $userId, $file), $files);
    }

    /**
     * Builds and executes an ISearchQuery with filters (mimetype, name, mtime) and mtime DESC ordering.
     *
     * @return list<array<string, mixed>>
     */
    private function searchByQuery(
        Folder $root,
        Folder $scope,
        string $userId,
        string $query,
        ?int $after,
        ?int $before,
        int $limit,
    ): array {
        $conditions = [
            new SearchComparison('mimetype', ISearchComparison::COMPARE_LIKE, 'image/%'),
        ];
        if ($query !== '') {
            $pattern = '%' . $this->db->escapeLikeParameter($query) . '%';
            $conditions[] = new SearchComparison('name', ISearchComparison::COMPARE_LIKE, $pattern);
        }
        if ($after !== null) {
            $conditions[] = new SearchComparison('mtime', ISearchComparison::COMPARE_GREATER_THAN_EQUAL, $after);
        }
        if ($before !== null) {
            $conditions[] = new SearchComparison('mtime', ISearchComparison::COMPARE_LESS_THAN_EQUAL, $before);
        }

        $operation = SearchBinaryOperator::and(...$conditions);
        $order = [new SearchOrder('mtime', ISearchOrder::DIRECTION_DESCENDING)];
        $user = $this->userManager->get($userId);

        $out = [];
        $seenIds = [];
        $batchSize = $limit;
        $maxInspected = 500;
        $offset = 0;
        $totalInspected = 0;

        while (count($out) < $limit && $totalInspected < $maxInspected) {
            $fetchLimit = min($batchSize, $maxInspected - $totalInspected);
            $search = new NameSearchQuery($operation, $fetchLimit, $user, $order, $offset);
            $batch = $scope->search($search);
            $batchCount = 0;
            $newInBatch = 0;

            foreach ($batch as $node) {
                $batchCount++;
                $totalInspected++;
                $id = (int)$node->getId();
                if ($id > 0) {
                    if (isset($seenIds[$id])) {
                        continue;
                    }
                    $seenIds[$id] = true;
                }
                $newInBatch++;

                if ($node->getPath() === $scope->getPath() || !$node instanceof File || !$node->isReadable()) {
                    continue;
                }
                if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
                    continue;
                }
                $out[] = $this->searchEntry($root, $userId, $node);
                if (count($out) >= $limit) {
                    break 2;
                }
            }

            if ($batchCount === 0 || $newInBatch === 0 || $batchCount < $fetchLimit) {
                break;
            }

            $offset += $batchCount;
        }

        return $out;
    }

    /**
     * Picks images under a folder, newest first, running the search as the authenticated user.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $folder user-relative folder path
     * @param int $limit maximum items to pick
     * @return list<array{0:string, 1:File|null, 2:string|null}> path, file, error (one of file/error is set)
     * @throws ToolFailure when the folder path is not a folder
     */
    private function selectFromFolder(Folder $root, string $userId, string $folder, int $limit): array {
        $normalized = PathGuard::normalize($folder, 'folder');
        $scope = NodeAccess::get($root, $normalized, $this->visibilityGuard);
        if (!$scope instanceof Folder) {
            throw new ToolFailure(FilesMessages::notAFolder());
        }
        $operation = new SearchComparison('mimetype', ISearchComparison::COMPARE_LIKE, 'image/%');
        $order = [new SearchOrder('mtime', ISearchOrder::DIRECTION_DESCENDING)];
        $user = $this->userManager->get($userId);
        $search = new NameSearchQuery($operation, min($limit, self::BATCH_MAX_ITEMS), $user, $order);
        $files = [];
        foreach ($scope->search($search) as $node) {
            if ($node instanceof File && $node->isReadable() && ($this->visibilityGuard === null || $this->visibilityGuard->isVisible($node))) {
                $files[] = $node;
            }
        }
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
            $path = PathGuard::normalize((string)$raw, 'paths');
            try {
                $node = NodeAccess::get($root, $path, $this->visibilityGuard);
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

    /** Searches for a tag by name, strictly restricted to user-visible tags. */
    private function findTag(string $tagName): ?ISystemTag {
        try {
            foreach ($this->tagManager->getAllTags(true, $tagName) as $tag) {
                if ($tag->isUserVisible() && $tag->getName() === $tagName) {
                    return $tag;
                }
            }
        } catch (\Throwable) {
            return null;
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
    private function searchEntry(Folder $root, string $userId, File $file): array {
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

    /**
     * @param Folder $root the user's folder
     * @param string $path user-relative path
     * @return File readable image file
     * @throws ToolFailure for a folder, a non-image or an unreadable node
     */
    private function requireImage(Folder $root, string $path): File {
        $node = NodeAccess::get($root, $path, $this->visibilityGuard);
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
