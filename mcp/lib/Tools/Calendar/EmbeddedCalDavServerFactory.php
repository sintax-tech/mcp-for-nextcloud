<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Sabre\DAV\Server;

/**
 * Builds the embedded, non-public CalDAV server {@see EmbeddedDavDispatcher} runs every write through, on every
 * supported Nextcloud.
 *
 * - Nextcloud 32 and 33 have `OCA\DAV\CalDAV\EmbeddedCalDavServer`, used as it is.
 * - Nextcloud 31 only has its predecessor, `OCA\DAV\CalDAV\InvitationResponse\InvitationResponseServer`
 *   (apps/dav/lib/CalDAV/InvitationResponse/InvitationResponseServer.php of v31.0.0): the same plugins and the same
 *   `$public` switch, but without the IMipPlugin, which 32 added to the embedded server under `dav/sendInvitations`
 *   (apps/dav/lib/CalDAV/EmbeddedCalDavServer.php:97-99 of v32.0.0). It is added here under the same switch, so an
 *   invitation the user asked for still goes out by e-mail; a server that already has one is left alone.
 *
 * Both classes belong to the DAV app and are named by string, so this class loads without it.
 */
final class EmbeddedCalDavServerFactory {
    /** Embedded CalDAV server of Nextcloud 32 and later. */
    public const EMBEDDED = 'OCA\\DAV\\CalDAV\\EmbeddedCalDavServer';
    /** Its predecessor, the only embedded CalDAV server of Nextcloud 31. */
    public const LEGACY = 'OCA\\DAV\\CalDAV\\InvitationResponse\\InvitationResponseServer';
    /** Nextcloud plugin that sends the iMIP e-mails of a scheduling message. */
    public const IMIP_PLUGIN = 'OCA\\DAV\\CalDAV\\Schedule\\IMipPlugin';
    /** Sabre name of that plugin, the one `Server::getPlugin()` answers to. */
    private const IMIP_NAME = 'imip';
    /** App and key of the switch both Nextcloud servers read before loading the IMipPlugin. */
    private const DAV_APP = 'dav';
    private const SEND_INVITATIONS_KEY = 'sendInvitations';
    private const SEND_INVITATIONS_ON = 'yes';

    /**
     * @param ContainerInterface $container resolves the app config and the IMipPlugin, only on Nextcloud 31
     * @param string $embedded class of the Nextcloud 32+ server; replaced by tests only
     * @param string $legacy class of the Nextcloud 31 server; replaced by tests only
     */
    public function __construct(
        private ContainerInterface $container,
        private string $embedded = self::EMBEDDED,
        private string $legacy = self::LEGACY,
    ) {}

    /**
     * @return Server a fresh non-public embedded server, the one that takes a principal from CustomPrincipalPlugin
     * @throws \RuntimeException when the DAV app provides neither server
     */
    public function create(): Server {
        if (class_exists($this->embedded)) {
            return (new $this->embedded(false))->getServer();
        }
        if (!class_exists($this->legacy)) {
            throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
        }
        $server = (new $this->legacy(false))->getServer();
        if ($server->getPlugin(self::IMIP_NAME) === null && $this->invitationsOn()) {
            $server->addPlugin($this->container->get(self::IMIP_PLUGIN));
        }
        return $server;
    }

    /** @return bool whether the admin left dav/sendInvitations on, as Nextcloud reads it */
    private function invitationsOn(): bool {
        return $this->container->get(IAppConfig::class)
            ->getValueString(self::DAV_APP, self::SEND_INVITATIONS_KEY, self::SEND_INVITATIONS_ON) === self::SEND_INVITATIONS_ON;
    }
}
