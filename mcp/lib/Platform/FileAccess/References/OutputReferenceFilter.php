<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\FileAccess\References;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use Throwable;

/**
 * The single output rule for structured references to Files nodes that other modules carry: the attachmentId of a
 * Talk room share and the ATTACH property of a task. Before such a reference is exposed in a read, a plan, a
 * before/after, a confirmed result or an error, it is resolved in the user's own tree and passes, in this order, the
 * Files scope, the exclusion tags and the native access. A reference that fails any of them, or that cannot be
 * resolved, is omitted. Free text is never redacted.
 *
 * Nothing here fetches a URL: a URL in a Nextcloud shape is mapped to a file id or a user path by its shape alone, on
 * any host, and a URL in no such shape on another host is kept as it is. With no exclusion configured and no Files
 * scope, every reference passes untouched, as before.
 */
class OutputReferenceFilter {
    public function __construct(
        private IRootFolder $rootFolder,
        private IShareManager $shareManager,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private VisibilityGuard $guard,
        private FileReferenceScope $scope,
        private ?GuestRestrictions $guests = null,
    ) {}

    /**
     * @param string $userId authenticated user
     * @return bool whether any gate can refuse a reference for this user; an unreadable exclusion configuration counts
     */
    public function active(string $userId): bool {
        // The scope is read first, whatever the tags: an unreadable scope is unavailability (503) in every case,
        // never hidden behind the exclusion that happens to be configured.
        $restricted = $this->scope->restricts($userId);
        return $restricted
            || ($this->guests !== null && $this->guests->hidesExternalStorage($userId))
            || !$this->guard->isConfigurationValid()
            || $this->guard->getHiddenTagIds() !== [];
    }

    /**
     * Reads the user's Files scope before a file or a share is looked up, so a scope that cannot be read answers the
     * same 503 for a reference that exists and for one that does not (D6): a lookup that ran first would let the
     * existing one alone reach the failure.
     *
     * @param string $userId authenticated user
     * @throws PolicyUnavailable when the scope cannot be read
     */
    public function assertAvailable(string $userId): void {
        $this->scope->restricts($userId);
    }

    /**
     * The attachment id a message may show. The message text is never touched.
     *
     * @param string $userId authenticated user
     * @param string $conversationToken conversation the message was read from
     * @param int|null $attachmentId bare room share id carried by the Talk envelope
     * @return int|null the same id, or null when its file is refused or the share cannot be resolved to it
     */
    public function attachmentId(string $userId, string $conversationToken, ?int $attachmentId): ?int {
        if ($attachmentId === null || !$this->active($userId)) {
            return $attachmentId;
        }
        try {
            $share = $this->shareManager->getShareById(RoomShareId::full($attachmentId), $userId);
            if ($share->getShareType() !== IShare::TYPE_ROOM || (string)$share->getSharedWith() !== $conversationToken) {
                return null;
            }
            return $this->allowsRoomShare($userId, $share) ? $attachmentId : null;
        } catch (PolicyUnavailable $e) {
            throw $e; // unavailability is never a refusal of one reference
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The rule a write naming a room share follows before it describes or republishes the file: the same one the
     * reading applies, resolved in the caller's tree, so a refused attachment never reappears through a quote.
     *
     * @param string $userId authenticated user
     * @param IShare $share room share already matched to the conversation
     * @return bool whether its file passes the Files scope, the exclusion and the native access in the caller's tree
     */
    public function allowsRoomShare(string $userId, IShare $share): bool {
        if (!$this->active($userId)) {
            return true;
        }
        try {
            return $this->allowsFileId($userId, (int)$share->getNodeId());
        } catch (PolicyUnavailable $e) {
            throw $e; // unavailability is never a refusal of one reference
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The same rule for a node the caller named by path: every alias of its id in the caller's tree must pass, so a
     * clean path cannot publish a file whose other alias is excluded.
     *
     * @param string $userId authenticated user
     * @param Node $node node resolved in the caller's tree
     * @return bool whether a reference to it may be created and shown
     */
    public function allowsUserNode(string $userId, Node $node): bool {
        if ($this->guests !== null && !$this->guests->allowsNode($userId, $node)) {
            return false;
        }
        if (!$this->active($userId)) {
            return true;
        }
        try {
            return $this->allowsFileId($userId, (int)$node->getId());
        } catch (PolicyUnavailable $e) {
            throw $e; // unavailability is never a refusal of one reference
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The representation of a task calendar a tool may show. The original object is never changed: it stays the one
     * written back to DAV, so an edit of another field cannot drop a persisted ATTACH, and a plan that sanitizes both
     * sides the same way shows no removal as a change.
     *
     * @param string $userId authenticated user
     * @param VCalendar $calendar calendar as read from or prepared for DAV
     * @return VCalendar the calendar itself when nothing can be refused, otherwise a copy without the refused ATTACH
     */
    public function outputCalendar(string $userId, VCalendar $calendar): VCalendar {
        if (!$this->active($userId)) {
            return $calendar;
        }
        $copy = clone $calendar;
        $this->sanitizeComponent($userId, $copy);
        return $copy;
    }

    /** Removes the refused ATTACH of a component and of every component nested in it, alarms included. */
    private function sanitizeComponent(string $userId, Component $component): void {
        foreach ($component->select('ATTACH') as $attach) {
            if ($attach instanceof Property && !$this->keepsAttach($userId, $attach)) {
                $component->remove($attach);
            }
        }
        foreach ($component->getComponents() as $child) {
            $this->sanitizeComponent($userId, $child);
        }
    }

    /**
     * Decides an ATTACH of the copy, and drops from it a FILENAME that cannot be checked.
     *
     * @return bool whether the ATTACH may stay in the output: its X-NC-FILE-ID and FILENAME parameters, written by the
     *              Calendar app, must name allowed nodes; then inline content and URLs naming no node stay, and a URL
     *              in a Nextcloud shape stays only when every node it names is allowed
     */
    private function keepsAttach(string $userId, Property $attach): bool {
        if (isset($attach['X-NC-FILE-ID'])) {
            $fileId = trim((string)$attach['X-NC-FILE-ID']);
            if (!ctype_digit($fileId) || $this->fileIdVerdict($userId, (int)$fileId) !== true) {
                return false;
            }
        }
        if (isset($attach['FILENAME'])) {
            $verdict = $this->pathVerdict($userId, (string)$attach['FILENAME']);
            if ($verdict === false) {
                return false;
            }
            if ($verdict === null) {
                unset($attach['FILENAME']); // a name the user's tree does not have cannot be checked, so it is not shown
            }
        }
        // Inline content is the data itself, not a reference to a node.
        if (strtoupper((string)($attach['VALUE'] ?? '')) === 'BINARY' || strtoupper((string)($attach['ENCODING'] ?? '')) === 'BASE64') {
            return true;
        }
        return $this->urlVerdict($userId, trim((string)$attach->getValue()));
    }

    /**
     * Whether a URL may be shown. The Nextcloud shapes are mapped on any host, since a loopback address, an IP or an
     * alias missing from trusted_domains still reaches this server and the text alone would reveal the node; a link
     * to another server that happens to name a refused node of the user is removed too, on the safe side.
     *
     * Supported shapes: /f/{fileid}, /apps/files/files/{fileid}, /apps/files/?fileid=&dir=, /s/{token},
     * /remote.php/dav/files/{uid}/… and /remote.php/webdav/…, with or without /index.php and the web root, relative
     * or absolute. On a host of this server, a URL of any other shape, or one naming a node missing from the user's
     * tree or no checkable node, is removed; on another host only a node of this server that is refused removes it.
     */
    private function urlVerdict(string $userId, string $url): bool {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        if (!isset($parts['host']) && $scheme !== null) {
            return true; // cid:, mailto:, urn: and the like do not name a node
        }
        $local = !isset($parts['host']) || $this->isLocalHost($parts['host'], $parts['port'] ?? null);
        if ($local && $scheme !== null && $scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        parse_str($parts['query'] ?? '', $query);
        $references = $this->shapeReferences($this->withoutWebroot($parts['path'] ?? ''), $query, $local);
        if ($references === null) {
            return !$local; // no supported shape: on this server it cannot be checked, elsewhere it names no node
        }
        if ($references === []) {
            return !$local; // a shape naming no node: on this server it cannot be checked, elsewhere it names none here
        }
        foreach ($references as $reference) {
            $verdict = match (true) {
                array_key_exists('verdict', $reference) => $reference['verdict'],
                isset($reference['id']) => $this->fileIdVerdict($userId, $reference['id']),
                default => $this->pathVerdict($userId, $reference['path'], $reference['uid'] ?? null),
            };
            if ($verdict === false || ($verdict === null && $local)) {
                return false;
            }
        }
        return true;
    }

    private function withoutWebroot(string $path): string {
        $webroot = rtrim($this->urlGenerator->getWebroot(), '/');
        if ($webroot !== '' && ($path === $webroot || str_starts_with($path, $webroot . '/'))) {
            $path = substr($path, strlen($webroot));
        }
        return $path;
    }

    /**
     * The nodes a URL path names, by its shape only. On another host the web root is unknown, so the shape may follow
     * any prefix.
     *
     * @param array<mixed> $query parsed query string
     * @return list<array{id:int}|array{path:string, uid?:string}|array{verdict:bool|null}>|null null when no shape
     *         matches; an empty list for a shape that names no node; a null verdict for a part that cannot be checked
     */
    private function shapeReferences(string $path, array $query, bool $local): ?array {
        $references = $this->matchShape($path, $query);
        if ($references !== null || $local) {
            return $references;
        }
        for ($offset = strpos($path, '/', 1); $offset !== false; $offset = strpos($path, '/', $offset + 1)) {
            $references = $this->matchShape(substr($path, $offset), $query);
            if ($references !== null) {
                return $references;
            }
        }
        return null;
    }

    /**
     * @param array<mixed> $query parsed query string
     * @return list<array{id:int}|array{path:string, uid?:string}|array{verdict:bool|null}>|null
     */
    private function matchShape(string $path, array $query): ?array {
        if ($path === '/index.php' || str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php'));
        }
        $references = [];
        if (preg_match('#^/(?:f|apps/files/files)/(\d+)/?$#', $path, $match) === 1) {
            $references[] = ['id' => (int)$match[1]];
        } elseif (preg_match('#^/s/([^/]+)(?:/download)?/?$#', $path, $match) === 1) {
            // A path or file list inside a shared folder names a descendant the share root does not stand for.
            $references[] = $this->shareTokenReference(rawurldecode($match[1]), isset($query['path']) || isset($query['files']));
        } elseif (preg_match('#^/remote\.php/dav/files/([^/]+)(/.*)?$#', $path, $match) === 1) {
            $references[] = ['uid' => rawurldecode($match[1]), 'path' => rawurldecode($match[2] ?? '/')];
        } elseif (preg_match('#^/remote\.php/webdav(/.*)?$#', $path, $match) === 1) {
            $references[] = ['path' => rawurldecode($match[1] ?? '/')];
        } elseif (preg_match('#^/apps/files(?:/.*)?$#', $path) !== 1) {
            return null;
        } elseif (isset($query['dir'])) {
            // The folder the Files app opens is named by the URL as well.
            $references[] = is_string($query['dir']) ? ['path' => $query['dir']] : ['verdict' => null];
        }
        // A fileid in the query names a node too, whatever the path says; every node named has to pass.
        if (isset($query['fileid'])) {
            $references[] = is_string($query['fileid']) && ctype_digit($query['fileid']) ? ['id' => (int)$query['fileid']] : ['verdict' => null];
        }
        return $references;
    }

    /**
     * The node behind a public share token, without following the link. With an exclusion configured, or an
     * unreadable configuration, a folder link is refused whatever its root shows: it is an anonymous capability over
     * the whole subtree, hidden descendants included (D7).
     *
     * @param bool $descendant whether the URL names a path or files inside the share, which cannot be checked
     * @return array{id:int}|array{verdict:bool|null} a null verdict when no share of this server has the token
     */
    private function shareTokenReference(string $token, bool $descendant): array {
        try {
            $share = $this->shareManager->getShareByToken($token);
        } catch (Throwable) {
            return ['verdict' => null];
        }
        if ($descendant) {
            return ['verdict' => false];
        }
        try {
            $exclusion = !$this->guard->isConfigurationValid() || $this->guard->getHiddenTagIds() !== [];
            if ($exclusion && $share->getNodeType() !== 'file') {
                return ['verdict' => false];
            }
            return ['id' => (int)$share->getNodeId()];
        } catch (Throwable) {
            return ['verdict' => false];
        }
    }

    /**
     * Whether a host is this server: loopback, one of trusted_domains (with their wildcards), overwrite.cli.url or the
     * URL generator's own. ITrustedDomainHelper is not used: with overwritehost set it trusts every host, which would
     * remove every external link.
     */
    private function isLocalHost(string $host, ?int $port): bool {
        // A trailing dot or an escaped letter still reaches this server, so neither makes a URL external.
        $host = rtrim(strtolower(trim(rawurldecode($host), '[]')), '.');
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'))) {
            return true;
        }
        $candidates = [$host];
        if ($port !== null) {
            $candidates[] = $host . ':' . $port;
        }
        $patterns = $this->config->getSystemValue('trusted_domains', []);
        $patterns = is_array($patterns) ? $patterns : [];
        foreach ([(string)$this->config->getSystemValue('overwrite.cli.url', ''), $this->urlGenerator->getAbsoluteURL('/')] as $own) {
            $ownHost = parse_url($own, PHP_URL_HOST);
            if (is_string($ownHost) && $ownHost !== '') {
                $ownPort = parse_url($own, PHP_URL_PORT);
                $patterns[] = is_int($ownPort) ? $ownHost . ':' . $ownPort : $ownHost;
            }
        }
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                continue;
            }
            $regex = '/^' . str_replace('\*', '[-.a-z0-9]*', preg_quote(strtolower(trim($pattern)), '/')) . '$/';
            foreach ($candidates as $candidate) {
                if (preg_match($regex, $candidate) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    private function allowsFileId(string $userId, int $id): bool {
        return $this->fileIdVerdict($userId, $id) === true;
    }

    /**
     * Every alias of the id in the user's tree must pass the scope and the exclusion, and one must be readable.
     *
     * @return bool|null whether the node is allowed; null when the user's tree has no node with that id
     */
    private function fileIdVerdict(string $userId, int $id): ?bool {
        if ($id <= 0) {
            return false;
        }
        try {
            $folder = $this->rootFolder->getUserFolder($userId);
            $nodes = (int)$folder->getId() === $id ? [$folder] : $folder->getById($id);
            return $nodes === [] ? null : $this->allowsNodes($userId, $nodes, $id);
        } catch (PolicyUnavailable $e) {
            throw $e; // unavailability is never a refusal of one reference
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The node at a path of the user's own tree; a path of another user's DAV tree is never resolved.
     *
     * @param string|null $uid owner the DAV URL names, when it names one
     * @return bool|null whether the node is allowed; null when the user's tree has no node at that path
     */
    private function pathVerdict(string $userId, string $path, ?string $uid = null): ?bool {
        if ($uid !== null && $uid !== $userId) {
            return null; // another user's DAV tree is not resolvable in this user's context
        }
        try {
            $normalized = PathGuard::normalize($path);
            $folder = $this->rootFolder->getUserFolder($userId);
            $node = $normalized === '/' ? $folder : $folder->get(ltrim($normalized, '/'));
        } catch (NotFoundException) {
            return null;
        } catch (PolicyUnavailable $e) {
            throw $e; // unavailability is never a refusal of one reference
        } catch (Throwable) {
            return false;
        }
        try {
            return $this->allowsNodes($userId, [$node], null);
        } catch (PolicyUnavailable $e) {
            throw $e; // unavailability is never a refusal of one reference
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<mixed> $nodes aliases of one node in the user's tree
     * @param int|null $id the id they must carry, when they were resolved by id
     */
    private function allowsNodes(string $userId, array $nodes, ?int $id): bool {
        $readable = false;
        foreach ($nodes as $node) {
            if (!$node instanceof Node || ($id !== null && (int)$node->getId() !== $id)) {
                return false;
            }
            if (!$this->scope->allows($userId, $node) || !$this->guard->isVisible($node)
                || ($this->guests !== null && !$this->guests->allowsNode($userId, $node))) {
                return false;
            }
            $readable = $readable || $node->isReadable();
        }
        return $readable;
    }
}
