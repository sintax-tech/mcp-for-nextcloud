<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Platform\Dav\MetadataServer;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Comments\ICommentsManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\NullLogger;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;
use Sabre\DAV\Tree;

/**
 * The comments and system-tag part of the native DAV server, built from the verbatim server sources of one pin.
 *
 * Mirrors the Files module's embedded server: the native `comments` and `systemtags-relations` collections, the native
 * `CustomPrincipalPlugin`, `SystemTagPlugin` and `CommentsPlugin`, the request URL set before the plugins. The only
 * addition is an authentication backend that records every call, so a test can prove no backend ever runs.
 */
final class MetadataDavNative implements MetadataServer {
	/** Verbatim files, in declaration order (parents first). */
	public const FILES = [
		'apps/dav/lib/CalDAV/Auth/CustomPrincipalPlugin.php',
		'apps/dav/lib/Comments/RootCollection.php',
		'apps/dav/lib/Comments/EntityTypeCollection.php',
		'apps/dav/lib/Comments/EntityCollection.php',
		'apps/dav/lib/Comments/CommentNode.php',
		'apps/dav/lib/Comments/CommentsPlugin.php',
		'apps/dav/lib/Connector/Sabre/FilesPlugin.php',
		'apps/dav/lib/SystemTag/SystemTagNode.php',
		'apps/dav/lib/SystemTag/SystemTagMappingNode.php',
		'apps/dav/lib/SystemTag/SystemTagsObjectMappingCollection.php',
		'apps/dav/lib/SystemTag/SystemTagsObjectTypeCollection.php',
		'apps/dav/lib/SystemTag/SystemTagsRelationsCollection.php',
		'apps/dav/lib/SystemTag/SystemTagPlugin.php',
		'apps/comments/lib/Listener/CommentsEntityEventListener.php',
	];
	/** Fixture directory. */
	public const DIR = __DIR__ . '/../../../fixtures/dav-metadata-native';

	/** @var list<string> paths of every server built */
	public array $built = [];
	/** @var int authentication backend calls across every server */
	public int $backendCalls = 0;
	/** @var Server|null last server built */
	public ?Server $last = null;

	/**
	 * @param ICommentsManager $comments native comments manager double
	 * @param ISystemTagManager $tags native tag manager double
	 * @param ISystemTagObjectMapper $mapper native tag mapper double
	 * @param IUserSession $session request session
	 * @param IRootFolder $roots native root folder double
	 * @param IUserManager $users native user manager double
	 * @param IGroupManager $groups native group manager double
	 * @param IEventDispatcher $events dispatcher that runs the native comments entity listener
	 */
	public function __construct(
		private ICommentsManager $comments,
		private ISystemTagManager $tags,
		private ISystemTagObjectMapper $mapper,
		private IUserSession $session,
		private IRootFolder $roots,
		private IUserManager $users,
		private IGroupManager $groups,
		private IEventDispatcher $events,
	) {}

	/**
	 * @param string $major source pin, 34 or 35
	 * @return void loads the verbatim declarations of that pin only
	 */
	public static function load(string $major): void {
		foreach (self::FILES as $file) {
			require_once self::DIR . '/' . $major . '/' . $file;
		}
	}

	/** {@inheritDoc} */
	public function create(string $path): Server {
		$root = new SimpleCollection('root', [
			new \OCA\DAV\Comments\RootCollection($this->comments, $this->users, $this->session, $this->events, new NullLogger()),
			new \OCA\DAV\SystemTag\SystemTagsRelationsCollection($this->tags, $this->mapper, $this->session, $this->groups, $this->events, $this->roots),
		]);
		$server = new Server(new Tree($root));
		$server->setBaseUri('/remote.php/dav/');
		$server->httpRequest->setUrl('/remote.php/dav/' . $path);
		$auth = new \OCA\DAV\CalDAV\Auth\CustomPrincipalPlugin();
		$auth->addBackend(new RecordingBackend(function (): void { $this->backendCalls++; }));
		$server->addPlugin($auth);
		$server->addPlugin(new \OCA\DAV\SystemTag\SystemTagPlugin($this->tags, $this->groups, $this->session, $this->roots, $this->mapper));
		$server->addPlugin(new \OCA\DAV\Comments\CommentsPlugin($this->comments, $this->session));
		$this->built[] = $path;
		return $this->last = $server;
	}
}
