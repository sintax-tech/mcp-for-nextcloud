<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Search;

use OCA\Mcp\Modules\Files\Reading\FileEntry;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IDBConnection;
use OCP\IUserManager;

final class FileSearch {
    public const SEARCH_OVERFETCH = 10;

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private FileEntry $entries,
        private FilesAccessGuard $visibilityGuard,
        private ?IAppManager $appManager = null,
        private ?IFullTextSearchManager $ftsManager = null,
    ) {}

    /**
     * @return array{search_mode:string, full_text_active:bool, notice?:string, files:list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>, excerpts?:list<string>}>}
     */
    public function search(Folder $root, string $userId, string $query, int $limit, string $mode = 'auto'): array {
        if ($mode === 'name') {
            return [
                'search_mode' => 'name_only',
                'full_text_active' => $this->isFullTextAvailable($userId),
                'files' => $this->searchByName($root, $userId, $query, $limit),
            ];
        }

        if (!$this->isFullTextAvailable($userId)) {
            return [
                'search_mode' => 'name_only',
                'full_text_active' => false,
                'notice' => SearchMessages::searchNameOnlyNotice(),
                'files' => $this->searchByName($root, $userId, $query, $limit),
            ];
        }

        try {
            $results = $this->ftsManager->search([
                'search' => $query,
                'providers' => ['files'],
                'size' => $limit + self::SEARCH_OVERFETCH,
                'page' => 1,
            ], $userId);

            $files = [];
            $seen = [];
            foreach ($results as $result) {
                if (!$result instanceof ISearchResult) {
                    continue;
                }
                foreach ($result->getDocuments() as $doc) {
                    $fileId = (int)$doc->getId();
                    if ($fileId <= 0) {
                        continue;
                    }
                    $nodes = $root->getById($fileId);
                    if ($nodes === []) {
                        continue;
                    }
                    $visibleNodes = ($this->visibilityGuard->filter($nodes) ?? array_values($nodes));
                    if ($visibleNodes === []) {
                        continue;
                    }
                    $node = $visibleNodes[0];
                    if ($node->getPath() === $root->getPath() || !$node->isReadable()) {
                        continue;
                    }
                    if (isset($seen[$node->getId()])) {
                        continue;
                    }
                    $seen[$node->getId()] = true;

                    $snippets = [];
                    foreach ($doc->getExcerpts() as $item) {
                        if (is_array($item) && isset($item['excerpt']) && is_string($item['excerpt'])) {
                            $snippets[] = trim(strip_tags($item['excerpt']));
                        } elseif (is_string($item)) {
                            $snippets[] = trim(strip_tags($item));
                        }
                    }
                    $snippets = array_values(array_filter($snippets, static fn (string $s) => $s !== ''));

                    $entry = $this->entries->describe($root, $userId, $node);
                    if ($snippets !== []) {
                        $entry['excerpts'] = $snippets;
                    }
                    $files[] = $entry;
                    if (count($files) >= $limit) {
                        break 2;
                    }
                }
            }

            return [
                'search_mode' => 'content',
                'full_text_active' => true,
                'files' => $files,
            ];
        } catch (\Throwable) {
            return [
                'search_mode' => 'name_only',
                'full_text_active' => false,
                'notice' => SearchMessages::searchFallbackNotice(),
                'files' => $this->searchByName($root, $userId, $query, $limit),
            ];
        }
    }

    /**
     * Checks whether the user's full-text apps and indexed Files provider are available.
     *
     * @param string $userId authenticated user whose enabled apps are checked
     * @return bool false when the service is absent or its availability check fails
     */
    private function isFullTextAvailable(string $userId): bool {
        if ($this->appManager === null || $this->ftsManager === null) {
            return false;
        }
        $user = $this->userManager->get($userId);
        if (!$this->appManager->isEnabledForUser('fulltextsearch', $user)
            || !$this->appManager->isEnabledForUser('files_fulltextsearch', $user)) {
            return false;
        }
        try {
            return $this->ftsManager->isAvailable() && $this->ftsManager->isProviderIndexed('files');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>}> */
    private function searchByName(Folder $root, string $userId, string $query, int $limit): array {
        // %, _ and \ in the term are literal: escaped the way core's own file search escapes LIKE terms.
        $pattern = '%' . $this->db->escapeLikeParameter($query) . '%';
        $user = $this->userManager->get($userId);
        $out = [];
        $seenIds = [];
        $batchSize = $limit + self::SEARCH_OVERFETCH;
        $maxInspected = 500;
        $offset = 0;
        $totalInspected = 0;

        while (count($out) < $limit && $totalInspected < $maxInspected) {
            $fetchLimit = min($batchSize, $maxInspected - $totalInspected);
            $search = new NameSearchQuery(new NameLikeComparison($pattern), $fetchLimit, $user, [], $offset);
            $batch = $root->search($search);
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

                if ($node->getPath() === $root->getPath() || !$node->isReadable()) {
                    continue;
                }
                if (!$this->visibilityGuard->isVisible($node)) {
                    continue;
                }
                $out[] = $this->entries->describe($root, $userId, $node);
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
}
