<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Creation;

use OCA\Mcp\Tools\Forms\FormsServices;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IUserSession;

/** Builds captured-actor collaborators explicitly, without cached controllers, DI resets or impersonation. */
class FormsCreateNativeFactory {
	/** Receives real endpoint identity, native read admission and renewed effective policy.
	 * @param IUserSession $session Actual authenticated endpoint session.
	 * @param FormsServices $forms Native enablement and exact FormsService identity probe.
	 * @param CreatePolicyBoundary $policy Fresh MCP admission at stage boundaries.
	 * @param \OCA\Mcp\Service\GuestRestrictions $guests Existing per-app Guests restrictions.
	 */
	public function __construct(private IUserSession $session, private FormsServices $forms, private CreatePolicyBoundary $policy, private \OCA\Mcp\Service\GuestRestrictions $guests) {
	}
	/** Creates the version-specific native graph under the same real session and Forms namespace.
	 * @param string $uid Authenticated actor.
	 * @return CreateFormContext Fresh write graph; its FormsService is the proven instance.
	 * @throws ToolFailure Before any write if context or constructor contract is unavailable.
	 */
	public function open(string $uid): CreateFormContext {
		try {
			$this->assertSession($uid); $this->policy->assertAllowed($uid);
			if (!$this->guests->allowsApp($uid, 'forms')) { throw new ToolFailure(CreateFormMessages::refused()); }
			$read = $this->forms->open($uid); $version = $read['version'];
			if (!in_array($version, CreateFormTypes::VERSIONS, true)) { throw new ToolFailure(CreateFormMessages::refused()); }
			$c = $this->container();
			$get = static fn (string $type): object => $c->get($type);
			$config = new \OCA\Forms\Service\ConfigService('forms', $get(\OCP\IConfig::class), $get(\OCP\AppFramework\Services\IAppConfig::class), $get(\OCP\IGroupManager::class), $this->session);
			$native = CreateNativeArguments::submission($version, $get, $this->session, $read['service']);
			$submission = new \OCA\Forms\Service\SubmissionService(...$native);
			$args = CreateNativeArguments::controller($version, $get, $this->session, $read, $config, $submission, $native);
			$context = new CreateFormContext($version, new \OCA\Forms\Controller\ApiController(...$args), $config, $read['service'], $read['mapper'], $native['questionMapper'], $native['optionMapper'], $get(\OCA\Forms\Helper\FilePathHelper::class), $submission);
			$this->assertAllowed($uid, $context);
			return $context;
		} catch (\Throwable $error) { throw new ToolFailure(CreateFormMessages::refused(), 0, $error); }
	}
	/** Rechecks admission without replacing the native graph or widening actor access.
	 * @param string $uid Authenticated actor.
	 * @param CreateFormContext $context Exact graph used for the next call.
	 * @return void
	 * @throws ToolFailure For changed or unavailable access, including captured identity.
	 */
	public function assertAllowed(string $uid, CreateFormContext $context): void {
		$this->assertSession($uid); $this->policy->assertAllowed($uid);
		if (!$this->guests->allowsApp($uid, 'forms')) { throw new ToolFailure(CreateFormMessages::refused()); }
		$read = $this->forms->open($uid);
		if ($read['service'] !== $context->service || $read['version'] !== $context->version || !$context->config->canCreateForms()) { throw new ToolFailure(CreateFormMessages::refused()); }
		$this->assertSession($uid);
	}
	/** Obtains Forms-scoped configuration and inert collaborators through the public app facade.
	 * @return object Native app container, possibly already registered; never cleared or modified.
	 */
	protected function container(): object { return (new \OCP\AppFramework\App('forms'))->getContainer(); }
	/** Checks the actual session, never an injected userId string or synthetic session.
	 * @param string $uid Authenticated actor.
	 * @return void
	 */
	private function assertSession(string $uid): void { if ($uid === '' || $this->session->getUser()?->getUID() !== $uid) { throw new ToolFailure(CreateFormMessages::refused()); } }
}
