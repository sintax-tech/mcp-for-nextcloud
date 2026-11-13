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
 * Reads the comments and the system tags of one file, changes one tag relation and adds one comment, through the native
 * DAV pipeline, as the authenticated actor.
 *
 * The MCP Files gate comes first, because the DAV app does not know it: Files scope, Guests external storage,
 * exclusion tags and the backup folder ({@see FilesAccessGuard}), on every alias of the file ID in the actor's root.
 * Then the request runs on the embedded native server ({@see MetadataServer}, {@see PinnedDispatch}), whose
 * collections decide the rest exactly as for a DAV client: the comments entity must resolve in the user folder
 * (apps/comments/lib/Listener/CommentsEntityEventListener.php:35-37 / 36-38 of v34.0.4 / v35.0.1), and a tag
 * relation needs the file in the user folder and returns only tags the user may see
 * (apps/dav/lib/SystemTag/SystemTagsRelationsCollection.php:40-48, SystemTagsObjectMappingCollection). A file
 * outside the gate, missing, or refused by DAV gives the same failure. Reading never moves the read marker: the
 * REPORT and the PROPFIND only read (CommentsPlugin::onReport, EntityCollection::getProperties); the marker moves on
 * POST and PROPPATCH.
 *
 * Two kinds of write, each built here from integers and fixed values, never on a Files node:
 * - one tag relation of one admitted file ({@see self::relate()}): PUT or DELETE on
 *   `systemtags-relations/files/<file ID>/<tag ID>`. The native collections decide them as for a web client:
 *   SystemTagsObjectMappingCollection::createFile (v34.0.4:39-58 / v35.0.1:40-59) and SystemTagMappingNode::delete
 *   (102-119 in both) check canUserSeeTag, canUserAssignTag and UPDATE on one alias of the file
 *   (SystemTagsRelationsCollection.php:53 / 54);
 * - one new comment on one admitted file ({@see self::comment()}): a POST of the fixed JSON
 *   `{actorType: "users", verb: "comment", message}` on `comments/files/<file ID>`. The native plugin takes the author
 *   from the session and moves the author's own read marker to now (CommentsPlugin::httpPost and createComment,
 *   CommentsPlugin.php:89-115 and 203-234 of v34.0.4, 90-116 and 204-235 of v35.0.1); the comments entity decides
 *   whether the item can be commented, as for a web client. PROPPATCH is never sent.
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
	/** The read marker of the actor on a file's comments (EntityCollection::PROPERTY_NAME_READ_MARKER). */
	private const MARKER_PROPFIND = '<?xml version="1.0" encoding="utf-8"?>'
		. '<D:propfind xmlns:D="DAV:" xmlns:oc="http://owncloud.org/ns"><D:prop><oc:readMarker/></D:prop></D:propfind>';
	/** The properties of one comment, those CommentNode::getPropertyNames() lists for the REPORT. */
	private const COMMENT_PROPFIND = '<?xml version="1.0" encoding="utf-8"?><D:propfind xmlns:D="DAV:" xmlns:oc="http://owncloud.org/ns"><D:prop>'
		. '<oc:id/><oc:parentId/><oc:topmostParentId/><oc:childrenCount/><oc:verb/><oc:actorType/><oc:actorId/><oc:creationDateTime/>'
		. '<oc:latestChildDateTime/><oc:objectType/><oc:objectId/><oc:message/><oc:actorDisplayName/><oc:isUnread/><oc:mentions/></D:prop></D:propfind>';
	/** DAV answers that read as a missing file, unchanged for the reads. */
	private const READ_REFUSALS = [403, 404];
	/** DAV answers that read as a missing file or tag on a relation write: 412 is createFile's unknown or unseen tag. */
	private const WRITE_REFUSALS = [403, 404, 412];
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
	 * The system tags the actor may see in the whole instance, as the native multistatus document of `systemtags/`
	 * (SystemTagsByIdCollection::getChildren: user-visible tags, every tag for an administrator).
	 *
	 * @param string $actor authenticated MCP actor
	 * @return DavResult status 207 and the multistatus body (the collection itself, then one response per tag)
	 * @throws RuntimeException when the session or the native pipeline answers unexpectedly
	 */
	public function catalog(string $actor): DavResult {
		return $this->send($actor, 'PROPFIND', 'systemtags/', ['Content-Type' => 'application/xml', 'Depth' => '1'], self::TAGS_PROPFIND);
	}

	/**
	 * Assigns or removes one existing tag on one file, through the native relation collection, as the actor.
	 *
	 * @param string $actor authenticated MCP actor
	 * @param int $fileId file ID in the actor's tree, admitted again here
	 * @param string $tagId positive tag ID
	 * @param bool $assigned true to assign (PUT, 201), false to remove (DELETE, 204)
	 * @return void
	 * @throws InvalidArgumentException when the tag ID is not a positive integer
	 * @throws ToolFailure when the file is not reachable, the tag is an MCP exclusion tag now, or DAV refuses the tag or the file
	 * @throws PolicyUnavailable when the Files scope cannot be read
	 * @throws RuntimeException when the session or the native pipeline answers unexpectedly
	 */
	public function relate(string $actor, int $fileId, string $tagId, bool $assigned): void {
		if (!ctype_digit($tagId) || ltrim($tagId, '0') === '') {
			throw new InvalidArgumentException('Tag ID out of range');
		}
		$this->admit($actor, $fileId);
		// The exclusion selection read now, not the one the plan saw: a tag the administrator selected meanwhile would
		// hide the file when assigned, or reveal it when removed. An unreadable selection refuses like a selected tag.
		$this->guard->clearCache();
		$hidden = $this->guard->getHiddenTagIds();
		if (!$this->guard->isConfigurationValid() || in_array($tagId, $hidden, true)) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		$this->send($actor, $assigned ? 'PUT' : 'DELETE', 'systemtags-relations/files/' . $fileId . '/' . $tagId, [], '',
			$assigned ? 201 : 204, self::WRITE_REFUSALS);
	}

	/**
	 * The actor's own read marker on the file's comments, as the native multistatus document.
	 *
	 * @param string $actor authenticated MCP actor
	 * @param int $fileId file ID in the actor's tree
	 * @return DavResult status 207 and the multistatus body, with an empty `oc:readMarker` when the actor has none
	 * @throws ToolFailure when the file is not reachable for the actor
	 * @throws PolicyUnavailable when the Files scope cannot be read
	 * @throws RuntimeException when the session or the native pipeline answers unexpectedly
	 */
	public function readMarker(string $actor, int $fileId): DavResult {
		$this->admit($actor, $fileId);
		return $this->send($actor, 'PROPFIND', 'comments/files/' . $fileId, ['Content-Type' => 'application/xml', 'Depth' => '0'], self::MARKER_PROPFIND);
	}

	/**
	 * Adds one comment to one file as the actor, through the native comments collection.
	 *
	 * The file is admitted again right here, with the Files gate cache cleared, so a change since the plan wins.
	 *
	 * @param string $actor authenticated MCP actor, the author the native plugin reads from the session
	 * @param int $fileId file ID in the actor's tree
	 * @param string $message exact text, already within the native limits
	 * @return string|null the ID of the new comment, read from the Content-Location the native POST answers with
	 *     (CommentsPlugin::httpPost, 34:108-110 / 35:109-111); null when the answer names none
	 * @throws ToolFailure when the file is not reachable, or DAV refuses the file
	 * @throws PolicyUnavailable when the Files scope cannot be read
	 * @throws RuntimeException when the session or the native pipeline answers unexpectedly (an invalid message included)
	 */
	public function comment(string $actor, int $fileId, string $message): ?string {
		$this->admit($actor, $fileId);
		$created = $this->send($actor, 'POST', 'comments/files/' . $fileId, ['Content-Type' => 'application/json'],
			json_encode(['actorType' => 'users', 'verb' => 'comment', 'message' => $message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 201);
		$id = rawurldecode((string)substr(strrchr('/' . (string)$created->location, '/'), 1));
		return ctype_digit($id) ? $id : null;
	}

	/**
	 * One comment of the file, by its ID, as the native multistatus document of a Depth 0 PROPFIND.
	 *
	 * @param string $actor authenticated MCP actor
	 * @param int $fileId file ID in the actor's tree
	 * @param string $commentId comment ID, digits only
	 * @return DavResult status 207 and the multistatus body with the properties a comments REPORT carries
	 * @throws InvalidArgumentException when the comment ID is not made of digits
	 * @throws ToolFailure when the file or the comment is not reachable for the actor
	 * @throws PolicyUnavailable when the Files scope cannot be read
	 * @throws RuntimeException when the session or the native pipeline answers unexpectedly
	 */
	public function commentById(string $actor, int $fileId, string $commentId): DavResult {
		if (!ctype_digit($commentId)) {
			throw new InvalidArgumentException('Comment ID out of range');
		}
		$this->admit($actor, $fileId);
		return $this->send($actor, 'PROPFIND', 'comments/files/' . $fileId . '/' . $commentId, ['Content-Type' => 'application/xml', 'Depth' => '0'], self::COMMENT_PROPFIND);
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
	 * @param string $method DAV method, fixed by the callers above
	 * @param string $path request path below the DAV base
	 * @param array<string,string> $headers request headers
	 * @param string $body request body
	 * @param int $expected the only status that counts as done: 207 for the reads
	 * @param list<int> $refusals DAV statuses answered like a missing file
	 * @return DavResult the native answer
	 * @throws ToolFailure when DAV refuses or does not find the file, with the same message as a missing file
	 * @throws RuntimeException when the session or the native pipeline answers unexpectedly
	 */
	private function send(string $actor, string $method, string $path, array $headers, string $body, int $expected = 207,
		array $refusals = self::READ_REFUSALS): DavResult {
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
			if (in_array($error->getHTTPCode(), $refusals, true)) {
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
		if ($response->getStatus() !== $expected) {
			throw new RuntimeException('Unexpected DAV metadata status');
		}
		return new DavResult($expected, null, $response->getBodyAsString(), $response->getHeader('Content-Location'));
	}
}
