<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\OAuth\Revocation\CredentialCommands;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revokes OAuth credentials as soon as Nextcloud disables the account: a cut at the user's issue_seq (contracts §3.1,
 * cut point (i)), never an unqualified delete. It never throws into the core and never runs SQL inside a caller's
 * transaction (contracts §3.3.6); when it cannot cut, the credentials still fail on their next use, because validation
 * checks the account and observes the revocation itself. An account deletion is revoked by its recheck cause instead
 * (UserCauseListener), so a late deletion event never cuts the consent of a recreated account.
 */
class UserRevocationListener implements IEventListener {
    public function __construct(private CredentialCommands $credentials, private IDBConnection $db, private LoggerInterface $logger,
        private CauseProcessor $causes) {}

    public function handle(Event $event): void {
        if (!($event instanceof UserChangedEvent && $event->getFeature() === 'enabled'
            && in_array($event->getValue(), [false, 0, '0', 'false'], true))) {
            return;
        }
        try {
            if ($this->db->inTransaction()) {
                $this->logger->info('Account revocation left to the next use: the event came inside a transaction', ['app' => 'mcp']);
                return;
            }
            $this->causes->flush();
            $this->credentials->revokeCredentials($event->getUser()->getUID());
        } catch (Throwable $e) {
            $this->logger->warning('Cannot revoke the OAuth credentials of a disabled account', ['app' => 'mcp', 'exception' => $e]);
        }
    }
}
