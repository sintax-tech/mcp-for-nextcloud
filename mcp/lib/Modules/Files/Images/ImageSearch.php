<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Search\NameSearchQuery;
use OCA\Mcp\Modules\Files\Search\SearchBinaryOperator;
use OCA\Mcp\Modules\Files\Search\SearchComparison;
use OCA\Mcp\Modules\Files\Search\SearchOrder;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

final class ImageSearch {
    public const SEARCH_DEFAULT_LIMIT = 25;
    public const SEARCH_MAX_LIMIT = 100;
    public const TAG_RESOLUTION_CEILING = 2000;

    public function __construct(
        private ImageMetadata $metadata,
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private IUserManager $userManager,
        private LoggerInterface $logger,
        private IDBConnection $db,
        private FilesAccessGuard $visibilityGuard,
    ) {}

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
        $scope = NodeAccess::getBase($root, $folder, $this->visibilityGuard);
        if (!$scope instanceof Folder) {
            throw new ToolFailure(ReadingMessages::notAFolder());
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
            $rawIds = $this->tagMapper->getObjectIdsForTags($tag->getId(), ImageMetadata::TAG_OBJECT_TYPE);
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
                if (!$this->visibilityGuard->isVisible($node)) {
                    continue;
                }
                $mime = (string)$node->getMimetype();
                if (!ImageSelector::looksLikeImage($mime)) {
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

        return array_map(fn (File $file) => $this->metadata->searchEntry($root, $userId, $file), $files);
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
                if (!$this->visibilityGuard->isVisible($node)) {
                    continue;
                }
                $out[] = $this->metadata->searchEntry($root, $userId, $node);
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
            throw new ToolFailure(ImageMessages::invalidDate($value));
        }
        return $time;
    }
}
