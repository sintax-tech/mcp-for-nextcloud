<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Mcp\Modules\Forms\FormsVersionProfiles;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Presentation\EditMessages;
use OCA\Mcp\Modules\Forms\Editing\EditPolicyBoundary;

use OCA\Mcp\Modules\Forms\Integration\FormsServices;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeArguments;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IUserSession;

/** Builds fresh captured-actor services through public constructors without requiring native creation permission. */
class EditNativeFactory {
	/** Receives real endpoint admission and identity collaborators.
	 * @param IUserSession $session Actual session, never replaced.
	 * @param FormsServices $forms Native app enablement and exact service probe.
	 * @param EditPolicyBoundary $policy Renewed effective edit policy.
	 * @param GuestRestrictions $guests Existing Guests restrictions.
	 */
	public function __construct(private IUserSession $session, private FormsServices $forms, private EditPolicyBoundary $policy, private GuestRestrictions $guests) { }
	/** Opens the versioned native graph without reusing a captured controller/config/submission service.
	 * @param string $uid Authenticated actor.
	 * @param callable(string):string|null $unsupportedVersion Caller-specific version refusal; point editing by default.
	 * @return EditContext Exact graph.
	 */
	public function open(string $uid, ?callable $unsupportedVersion = null): EditContext {
		try {
			$this->actor($uid); $read = $this->forms->open($uid);
			if (FormsVersionProfiles::get($read['version']) === null) { throw new ToolFailure(($unsupportedVersion ?? [EditMessages::class, 'unsupportedVersion'])($read['version'])); }
			$c = $this->container(); $get = static fn (string $type): object => $c->get($type);
			$config = new \OCA\Forms\Service\ConfigService('forms', $get(\OCP\IConfig::class), $get(\OCP\AppFramework\Services\IAppConfig::class), $get(\OCP\IGroupManager::class), $this->session);
			$native = CreateNativeArguments::submission($read['version'], $get, $this->session, $read['service']);
			$submission = new \OCA\Forms\Service\SubmissionService(...$native);
			$args = CreateNativeArguments::controller($read['version'], $get, $this->session, $read, $config, $submission, $native);
			$context = new EditContext($read['version'], new \OCA\Forms\Controller\ApiController(...$args), $config, $read['service'], $native['questionMapper'], $native['optionMapper'], $get(\OCA\Forms\Db\ShareMapper::class));
			$this->assertAllowed($uid, $context, $unsupportedVersion); return $context;
		} catch (ToolFailure $error) {
			throw $error;
		} catch (\Throwable $error) {
			throw new ToolFailure(EditMessages::refused(), 0, $error);
		}
	}
	/** Rechecks current effective policy/session/app/service without canCreateForms or an owner-only condition.
	 * @param string $uid Authenticated actor.
	 * @param EditContext $context Exact graph being used.
	 * @param callable(string):string|null $unsupportedVersion Caller-specific version refusal; point editing by default.
	 * @return void
	 */
	public function assertAllowed(string $uid, EditContext $context, ?callable $unsupportedVersion = null): void {
		$this->actor($uid); $read = $this->forms->open($uid);
		if (FormsVersionProfiles::get($read['version']) === null) { throw new ToolFailure(($unsupportedVersion ?? [EditMessages::class, 'unsupportedVersion'])($read['version'])); }
		if ($read['service'] !== $context->service || $read['version'] !== $context->version) { throw new ToolFailure(EditMessages::refused()); }
		$this->actor($uid);
	}
	/** Resolves the public Forms app facade, without resetting its container.
	 * @return object Native Forms container.
	 */
	protected function container(): object { return (new \OCP\AppFramework\App('forms'))->getContainer(); }
	/** Checks complete MCP admission, never a grant bit alone.
	 * @param string $uid Actor.
	 * @return void
	 */
	private function actor(string $uid): void {
		if ($uid === '' || $this->session->getUser()?->getUID() !== $uid || !$this->guests->allowsApp($uid, 'forms')) { throw new ToolFailure(EditMessages::refused()); }
		$this->policy->assertAllowed($uid);
	}
}
