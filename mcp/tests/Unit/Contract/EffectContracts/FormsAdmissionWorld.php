<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Policy\PolicyResolver;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Forms\Creation\CreateFormContext;
use OCA\Mcp\Tools\Forms\Creation\CreatePolicyBoundary;
use OCA\Mcp\Tools\Forms\Creation\FormsCreateNativeFactory;
use OCA\Mcp\Tools\Forms\Editing\EditContext;
use OCA\Mcp\Tools\Forms\Editing\EditNativeFactory;
use OCA\Mcp\Tools\Forms\Editing\EditPolicyBoundary;
use OCA\Mcp\Tools\Forms\FormsMessages;
use OCA\Mcp\Tools\Forms\FormsServices;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What the real Forms native factories ({@see FormsCreateNativeFactory}, {@see EditNativeFactory}) are admitted by, as
 * one mutable world: the account in the session, whether Guests allows Forms, the Forms version and service the read
 * probe returns, and the native canCreateForms answer. The renewed policy is the real boundary over the real resolver
 * of the gate, so revoking a grant on the gate is what "policy renewed" means here.
 *
 * The factories are the production classes; only their protected container() is overridden, to count how often the
 * native construction was reached. It hands back a container that resolves every service to a placeholder: the Forms
 * classes the factories then instantiate (ConfigService, SubmissionService, ApiController) do not exist outside a
 * Nextcloud with Forms installed, so a fully admitted open() stops right there.
 */
final class FormsAdmissionWorld {
	/** Account the session holds; null for no session. */
	public ?string $session = EffectsGate::USER;
	/** Whether Guests allows the Forms app for the account. */
	public bool $guestsAllowForms = true;
	/** Whether the Forms read probe answers at all (app enabled, service present). */
	public bool $formsAvailable = true;
	/** Version of Forms the read probe reports. */
	public string $version = '5.4.1';
	/** Whether the read probe returns a different FormsService instance than the one of the context. */
	public bool $otherService = false;
	/** What the native ConfigService::canCreateForms() answers. */
	public bool $canCreateForms = true;
	/** How many times a factory reached its native construction (opened the Forms container). */
	public int $containerOpened = 0;
	/** The FormsService instance the read probe proved. */
	public object $service;

	/**
	 * @param TestCase $test the running test, owner of the mocks
	 * @param EffectsGate $gate the gate whose real policy the boundaries renew
	 */
	public function __construct(private TestCase $test, private EffectsGate $gate) {
		$this->service = new \stdClass();
		$this->enableForms();
	}

	/**
	 * The real resolver of the gate, as InMemoryConfig builds it, sees the app of every module off; here Forms 5.4.1 is
	 * installed and enabled for the account, so the renewed policy decides by the grant and nothing else. Only that
	 * collaborator of the resolver is replaced.
	 *
	 * @return void
	 */
	private function enableForms(): void {
		$apps = $this->mock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$apps->method('getAppVersion')->willReturn('5.4.1');
		(new \ReflectionProperty(PolicyResolver::class, 'apps'))->setValue(InMemoryConfig::resolver($this->gate->config()), $apps);
	}

	/** @return FormsCreateNativeFactory the real creation factory over this world */
	public function createFactory(): FormsCreateNativeFactory {
		$opened = fn (): int => ++$this->containerOpened;
		return new class($this->session(), $this->forms(), new CreatePolicyBoundary(InMemoryConfig::resolver($this->gate->config())), $this->guests(), $opened)
			extends FormsCreateNativeFactory {
			/**
			 * @param IUserSession $session the session
			 * @param FormsServices $forms the read probe
			 * @param CreatePolicyBoundary $policy the renewed policy
			 * @param GuestRestrictions $guests Guests
			 * @param \Closure(): int $opened counts the native constructions reached
			 */
			public function __construct(IUserSession $session, FormsServices $forms, CreatePolicyBoundary $policy, GuestRestrictions $guests, private \Closure $opened) {
				parent::__construct($session, $forms, $policy, $guests);
			}

			/** @return object a container resolving every service to a placeholder */
			protected function container(): object {
				($this->opened)();
				return FormsAdmissionWorld::placeholders();
			}
		};
	}

	/** @return EditNativeFactory the real edit factory over this world */
	public function editFactory(): EditNativeFactory {
		$opened = fn (): int => ++$this->containerOpened;
		return new class($this->session(), $this->forms(), new EditPolicyBoundary(InMemoryConfig::resolver($this->gate->config())), $this->guests(), $opened)
			extends EditNativeFactory {
			/**
			 * @param IUserSession $session the session
			 * @param FormsServices $forms the read probe
			 * @param EditPolicyBoundary $policy the renewed policy
			 * @param GuestRestrictions $guests Guests
			 * @param \Closure(): int $opened counts the native constructions reached
			 */
			public function __construct(IUserSession $session, FormsServices $forms, EditPolicyBoundary $policy, GuestRestrictions $guests, private \Closure $opened) {
				parent::__construct($session, $forms, $policy, $guests);
			}

			/** @return object a container resolving every service to a placeholder */
			protected function container(): object {
				($this->opened)();
				return FormsAdmissionWorld::placeholders();
			}
		};
	}

	/** @return CreateFormContext a creation graph as open() would have built it, over the proven service of version 5.4.1 */
	public function createContext(): CreateFormContext {
		$config = new class($this) {
			/** @param FormsAdmissionWorld $world the world whose answer this config gives */
			public function __construct(private FormsAdmissionWorld $world) {
			}

			/** @return bool the native canCreateForms answer of the world */
			public function canCreateForms(): bool {
				return $this->world->canCreateForms;
			}
		};
		$none = new \stdClass();
		return new CreateFormContext('5.4.1', $none, $config, $this->service, $none, $none, $none, $none);
	}

	/** @return EditContext an edit graph as open() would have built it, over the proven service of version 5.4.1 */
	public function editContext(): EditContext {
		$none = new \stdClass();
		return new EditContext('5.4.1', $none, $none, $this->service, $none, $none, $none);
	}

	/** @return IUserSession the session, read on every call */
	private function session(): IUserSession {
		$session = $this->mock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->session === null) {
				return null;
			}
			$user = $this->mock(IUser::class);
			$user->method('getUID')->willReturn($this->session);
			return $user;
		});
		return $session;
	}

	/** @return FormsServices the read probe, answering from the world on every call */
	private function forms(): FormsServices {
		$forms = $this->mock(FormsServices::class);
		$forms->method('open')->willReturnCallback(function (string $uid): array {
			if (!$this->formsAvailable) {
				throw new ToolFailure(FormsMessages::unavailable());
			}
			return ['service' => $this->otherService ? new \stdClass() : $this->service, 'mapper' => new \stdClass(),
				'user' => $this->mock(IUser::class), 'version' => $this->version];
		});
		return $forms;
	}

	/** @return GuestRestrictions Guests, answering from the world on every call */
	private function guests(): GuestRestrictions {
		$guests = $this->mock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturnCallback(fn (string $uid, string $app): bool => $app !== 'forms' || $this->guestsAllowForms);
		return $guests;
	}

	/** @return object the Forms app container of this contract: every service resolves to a placeholder, nothing is built */
	public static function placeholders(): object {
		return new class {
			/**
			 * @param string $type service asked for
			 * @return object a placeholder
			 */
			public function get(string $type): object {
				return new \stdClass();
			}
		};
	}

	/**
	 * @param class-string $class type to mock
	 * @return MockObject mock owned by the running test
	 */
	private function mock(string $class): MockObject {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
