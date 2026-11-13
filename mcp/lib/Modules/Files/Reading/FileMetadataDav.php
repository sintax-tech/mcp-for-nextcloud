<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\Dav\DavResult;
use OCA\Mcp\Platform\Dav\MetadataServer;
use OCA\Mcp\Platform\Dav\PinnedDispatch;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sabre\DAV\Exception as DavException;
use Sabre\HTTP\Request;
use Throwable;

/**
 * Reads the comments and the system tags of one file through the native DAV pipeline, as the authenticated actor.
 *
 * The MCP Files gate comes first, because the DAV app does not know it: Files scope, Guests external storage,
 * exclusion tags and the backup folder ({@see FilesAccessGuard}), on every alias of the file ID in the actor's root.
 * Then the request runs on the embedded native server ({@see MetadataServer}, {@see PinnedDispatch}), whose
 * collections decide the rest exactly as for a DAV client: the comments entity must resolve in the user folder
 * (apps/comments/lib/Listener/CommentsEntityEventListener.php:35-37 / 36-38 of v34.0.4 / v35.0.1), and a tag
 * relation needs the file in the user folder and returns only tags the user may see
 * (apps/dav/lib/SystemTag/SystemTagsRelationsCollection.php:40-48, SystemTagsObjectMappingCollection). A file
 * outside the gate, missing, or refused by DAV gives the same failure. Reading never moves the read marker: the
 * REPORT only reads (CommentsPlugin::onReport); the marker moves on POST and PROPPATCH, which this class never sends.
 */
final class FileMetadataDav {
	/** The native comments REPORT (CommentsPlugin::REPORT_NAME). */
	private const COMMENTS_REPORT = '<?xml version="1.0" encoding="utf-8"?>'
		. '<oc:filter-comments xmlns:D="DAV:" xmlns:oc="http://owncloud.org/ns">'
		. '<oc:limit>%d</oc:limit><oc:offset>%d</oc:offset></oc:filter-comments>';
	/** The tag properties SystemTagPlugin answers on a relation (SystemTagPlugin::*_PROPERTYNAME). */
	private const TAGS_PROPFIND = '<?xml version="1.0" encoding="utf-8"?>'
		. '<D:propfind xmlns:D="DAV:" xmlns:oc="http://owncloud.org/ns"><D:prop>'
		. '<oc:id/><oc:display-name/><oc:user-visible/><oc:user-assignable/><oc:can-assign/>'
		. '</D:prop></D:propfind>';
	/** Hard limit of one native REPORT page (CommentsPlugin::onReport clamps to 1..100). */
	public const MAX_COMMENTS = 100;
	/** Alias bound, as for every Files read by ID. */
	private const MAX_ALIASES = 50;

	/**
	 * @param ActorRoot $roots the actor's own root, checked against the session
	 * @param FilesAccessGuard $guard Files scope, Guests external storage, exclusion tags and backups
	 * @param MetadataServer $servers fresh embedded native server per request
	 * @param PinnedDispatch $dispatch runs the request as the session's own actor
	 * @param LoggerInterface $logger refusal diagnostics, without content
	 */
	public function __construct(
		private ActorRoot $roots,
		private FilesAccessGuard $guard,
		private MetadataServer $servers,
		private PinnedDispatch $dispatch,
		private LoggerInterface $logger,
	) {}

	/**
	 * One page of the file's comments, newest first, as the native multistatus document.
	 *
	 * @param string $actor authenticated MCP actor
	 * @param int $fileId file ID in the actor's tree
	 * @param int $limit page size, 1 to {@see self::MAX_COMMENTS}
	 * @param int $offset comments to skip, 0 or more
	 * @return DavResult status 207 and the multistatus body
	 * @throws InvalidArgumentException when the page is out of range
	 * @throws ToolFailure when the file is not reachable for the actor
	 * @throws PolicyUnavailable when the Files scope cannot be read
	 * @throws RuntimeException when the session or the native pipeline answers unexpectedly
	 */
	public function comments(string $actor, int $fileId, int $limit, int $offset): DavResult {
		if ($limit < 1 || $limit > self::MAX_COMMENTS || $offset < 0) {
			throw new InvalidArgumentException('Comment page out of range');
		}
		$this->admit($actor, $fileId);
		return $this->send($actor, 'REPORT', 'comments/files/' . $fileId, ['Content-Type' => 'application/xml'], sprintf(self::COMMENTS_REPORT, $limit, $offset));
	}

	/**
	 * The system tags of the file the actor may see, as the native multistatus document.
	 *
	 * @param string $actor authenticated MCP actor
	 * @param int $fileId file ID in the actor's tree
	 * @return DavResult status 207 and the multistatus body (the relation itself, then one response per tag)
	 * @throws ToolFailure when the file is not reachable for the actor
	 * @throws PolicyUnavailable when the Files scope cannot be read
	 * @throws RuntimeException when the session or the native pipeline answers unexpectedly
	 */
	public function tags(string $actor, int $fileId): DavResult {
		$this->admit($actor, $fileId);
		return $this->send($actor, 'PROPFIND', 'systemtags-relations/files/' . $fileId, ['Content-Type' => 'application/xml', 'Depth' => '1'], self::TAGS_PROPFIND);
	}

	/**
	 * Refuses, like a missing file, an ID that has no alias in the actor's root or any alias the Files gate hides.
	 *
	 * @param string $actor authenticated MCP actor
	 * @param int $fileId file ID to admit
	 * @return void
	 * @throws ToolFailure when the file is not reachable
	 * @throws PolicyUnavailable when the Files scope cannot be read
	 */
	private function admit(string $actor, int $fileId): void {
		$this->guard->clearCache();
		$this->guard->assertScopeReadable();
		try {
			$root = $this->roots->get($actor);
			$nodes = $fileId > 0 && (int)$root->getId() === $fileId ? [$root] : ($fileId > 0 ? $root->getById($fileId) : []);
			$admitted = $nodes !== [] && count($nodes) <= self::MAX_ALIASES;
			foreach ($nodes as $node) {
				$admitted = $admitted && $node instanceof Node && (int)$node->getId() === $fileId
					&& $root->getRelativePath($node->getPath()) !== null && $node->isReadable() && $this->guard->isVisible($node);
			}
		} catch (PolicyUnavailable $error) {
			throw $error;
		} catch (Throwable) {
			$admitted = false;
		}
		if (!$admitted) {
			throw new ToolFailure(CommonMessages::notFound());
		}
	}

	/**
	 * @param string $actor authenticated MCP actor
	 * @param string $method DAV read method
	 * @param string $path request path below the DAV base
	 * @param array<string,string> $headers request headers
	 * @param string $body request body
	 * @return DavResult the native multistatus answer
	 * @throws ToolFailure when DAV refuses or does not find the file, with the same message as a missing file
	 * @throws RuntimeException when the session or the native pipeline answers unexpectedly
	 */
	private function send(string $actor, string $method, string $path, array $headers, string $body): DavResult {
		$server = $this->servers->create($path);
		$base = $server->getBaseUri();
		$request = new Request($method, $base . $path, $headers, $body);
		$request->setBaseUrl($base);
		try {
			$response = $this->dispatch->send($server, $actor, $request);
		} catch (DavException $error) {
			$this->logger->info('MCP DAV metadata read refused: {exception_class} (HTTP {http_code})', [
				'app' => 'mcp', 'exception_class' => $error::class, 'http_code' => $error->getHTTPCode(),
			]);
			if (in_array($error->getHTTPCode(), [403, 404], true)) {
				throw new ToolFailure(CommonMessages::notFound(), 404, $error);
			}
			throw new RuntimeException('DAV metadata read failed', 0, $error);
		} catch (RuntimeException $error) {
			throw $error;
		} catch (Throwable $error) {
			$this->logger->error('MCP DAV metadata dispatch failed: {exception_class} (code {exception_code})', [
				'app' => 'mcp', 'exception_class' => $error::class, 'exception_code' => ExceptionDetails::code($error),
			]);
			throw new RuntimeException('DAV metadata dispatch failed', 0, $error);
		}
		if ($response->getStatus() !== 207) {
			throw new RuntimeException('Unexpected DAV metadata status');
		}
		return new DavResult(207, null, $response->getBodyAsString());
	}
}
