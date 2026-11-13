<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Talk\Integration\TalkServices;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/** Constructs the lazy Talk resolver with the app container and binds the Talk steps of a meeting; no native Talk service is loaded at registration. */
final class TalkRegistration implements ModuleRegistration {
	/** {@inheritDoc} */
	public function register(IRegistrationContext $context): void {
		$context->registerService(TalkServices::class, static fn (ContainerInterface $c): TalkServices => new TalkServices(
			$c->get(IAppManager::class), $c, $c->get(IUserManager::class),
		));
		$context->registerService(\OCA\Mcp\Platform\Meetings\MeetingTalk::class, static fn (ContainerInterface $c): \OCA\Mcp\Platform\Meetings\MeetingTalk => $c->get(TalkMeetingSteps::class));
	}

	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> none: the existing per-user app gate is sufficient */
	public function probes(): array {
		return [];
	}
}
