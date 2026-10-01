<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files\Sharing;

use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\Sharing\LinkPassword;
use OCA\Mcp\Tools\ToolFailure;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\Security\Events\GenerateSecurePasswordEvent;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use OCP\Security\ISecureRandom;
use OCP\Security\PasswordContext;
use PHPUnit\Framework\TestCase;

/** The password of a public link: the policy generator first, the secure random fallback after, always validated. */
final class LinkPasswordTest extends TestCase {
    /** @var list<string> passwords the password_policy generator hands out, in order; empty means no generator */
    private array $generated = [];
    /** @var list<string> passwords the policy refuses */
    private array $refused = [];
    /** Refuse every password, as a policy no generated password can meet. */
    private bool $refuseAll = false;
    /** Validations refused before the policy accepts, whatever the password. */
    private int $refuseFirst = 0;
    /** @var list<string> every password the policy was asked about */
    private array $validated = [];
    /** @var list<object> events dispatched, in order */
    private array $events = [];

    private function passwords(?ISecureRandom $random = null): LinkPassword {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
            $this->events[] = $event;
            if ($event instanceof GenerateSecurePasswordEvent && $this->generated !== []) {
                $event->setPassword(array_shift($this->generated));
            }
            if ($event instanceof ValidatePasswordPolicyEvent) {
                $this->validated[] = $event->getPassword();
            }
            if ($event instanceof ValidatePasswordPolicyEvent
                && ($this->refuseAll || $this->refuseFirst-- > 0 || in_array($event->getPassword(), $this->refused, true))) {
                throw new HintException('Password needs at least 20 characters', 'Password needs at least 20 characters');
            }
        });
        return new LinkPassword($dispatcher, $random ?? new FakeSecureRandom());
    }

    public function testThePolicyGeneratorIsAskedFirstForTheSharingContext(): void {
        $this->generated = ['Policy-Made#Password1'];
        self::assertSame('Policy-Made#Password1', $this->passwords()->generate());
        self::assertInstanceOf(GenerateSecurePasswordEvent::class, $this->events[0]);
        self::assertSame(PasswordContext::SHARING, $this->events[0]->getContext());
        self::assertInstanceOf(ValidatePasswordPolicyEvent::class, $this->events[1]);
        self::assertSame(PasswordContext::SHARING, $this->events[1]->getContext());
    }

    /** No listener answers when password_policy is disabled: 16 characters of letters, digits and symbols. */
    public function testWithoutAGeneratorTheFallbackHasEveryKindOfCharacter(): void {
        $password = $this->passwords(new FakeSecureRandom(true))->generate();
        self::assertSame(LinkPassword::LENGTH, strlen($password));
        self::assertMatchesRegularExpression('/[A-Z]/', $password);
        self::assertMatchesRegularExpression('/[a-z]/', $password);
        self::assertMatchesRegularExpression('/[0-9]/', $password);
        self::assertMatchesRegularExpression('/[' . preg_quote(LinkPassword::SYMBOLS, '/') . ']/', $password);
        self::assertDoesNotMatchRegularExpression('/[\s"\'`\\\\<>]/', $password, 'copiável sem escapes');
    }

    public function testTheFallbackIsValidatedToo(): void {
        $this->passwords()->generate();
        self::assertSame([GenerateSecurePasswordEvent::class, ValidatePasswordPolicyEvent::class], array_map('get_class', $this->events));
    }

    public function testAGeneratedPasswordThePolicyRefusesFallsBackToTheRandomOne(): void {
        $this->generated = ['weak'];
        $this->refused = ['weak'];
        $password = $this->passwords()->generate();
        self::assertNotSame('weak', $password);
        self::assertSame(LinkPassword::LENGTH, strlen($password));
    }

    public function testARefusedFallbackIsGeneratedOnceMore(): void {
        $this->refuseFirst = 1;
        $password = $this->passwords()->generate();
        self::assertCount(2, $this->validated, 'validar, gerar de novo e validar');
        self::assertNotSame($this->validated[0], $password);
        self::assertSame($this->validated[1], $password);
    }

    public function testTwoRefusalsAreAClearErrorWithoutThePassword(): void {
        $this->refuseAll = true;
        try {
            $this->passwords()->generate();
            self::fail('no failure');
        } catch (ToolFailure $e) {
            self::assertSame(FilesMessages::linkPasswordRefused(), $e->getMessage());
        }
        self::assertCount(3, $this->events, 'no máximo duas senhas de reserva');
    }

    /** A generator that fails is like no generator at all. */
    public function testAFailingGeneratorIsIgnored(): void {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
            if ($event instanceof GenerateSecurePasswordEvent) {
                throw new HintException('generator broken');
            }
        });
        self::assertSame(LinkPassword::LENGTH, strlen((new LinkPassword($dispatcher, new FakeSecureRandom()))->generate()));
    }
}
