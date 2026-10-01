<?php
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;

/** Revoke OAuth credentials as soon as Nextcloud disables or deletes the account. */
class UserRevocationListener implements IEventListener {
    public function __construct(private OAuthStore $store) {}

    public function handle(Event $event): void {
        if ($event instanceof UserDeletedEvent
            || ($event instanceof UserChangedEvent && $event->getFeature() === 'enabled'
                && in_array($event->getValue(), [false, 0, '0', 'false'], true))) {
            $this->store->deleteForUser($event->getUser()->getUID());
        }
    }
}
