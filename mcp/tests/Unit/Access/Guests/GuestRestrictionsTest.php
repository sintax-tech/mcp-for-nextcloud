<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Guests;

use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GuestRestrictionsTest extends TestCase {
    /** @var list<string> calls the Guests doubles received, in order */
    private array $calls = [];
    private bool $guestsEnabled = true;
    private bool $isGuest = true;
    private bool $urlAllowed = true;
    private ?\Throwable $failure = null;
    private int $errors = 0;
    private int $warnings = 0;
    /** Guests before v4.8.0: setupRestrictions() reads the session user only */
    private bool $legacyGuests = false;
    private bool $externalAllowed = false;
    private bool $whitelistEnabled = true;
    /** @var list<string> apps on the Guests list */
    private array $whitelist = ['calendar'];

    private function restrictions(): GuestRestrictions {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForAnyone')->willReturnCallback(fn (string $app): bool => $app === 'guests' && $this->guestsEnabled);
        $request = $this->createMock(IRequest::class);
        $request->method('getPathInfo')->willReturn('/apps/mcp/');
        $test = $this;
        $services = [
            'OCA\Guests\GuestManager' => new class($test) {
                public function __construct(private GuestRestrictionsTest $test) {}
                public function isGuest(IUser|string $user): bool { return $this->test->record('isGuest:' . (is_string($user) ? $user : $user->getUID())); }
            },
            'OCA\Guests\AppWhitelist' => new class($test) {
                public function __construct(private GuestRestrictionsTest $test) {}
                public function isUrlAllowed(IUser $user, string|false $url): bool { return $this->test->record('isUrlAllowed:' . $url); }
                public function isWhitelistEnabled(): bool { return $this->test->record('isWhitelistEnabled'); }
                public function isAppWhitelisted(string $app): bool { return $this->test->record('isAppWhitelisted:' . $app); }
            },
            'OCA\Guests\RestrictionManager' => $this->legacyGuests ? new class($test) {
                public function __construct(private GuestRestrictionsTest $test) {}
                public function setupRestrictions(): void { $this->test->record('setupRestrictions:session'); }
                public function lateSetupRestrictions(): void { $this->test->record('lateSetupRestrictions'); }
            } : new class($test) {
                public function __construct(private GuestRestrictionsTest $test) {}
                public function setupRestrictions(?IUser $user = null): void { $this->test->record('setupRestrictions:' . $user?->getUID()); }
                public function lateSetupRestrictions(): void { $this->test->record('lateSetupRestrictions'); }
            },
        ];
        $services['OCA\Guests\Config'] = new class($test) {
            public function __construct(private GuestRestrictionsTest $test) {}
            public function allowExternalStorage(): bool { return $this->test->record('allowExternalStorage'); }
        };
        $service = function (string $id) use ($services): object {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            return $services[$id] ?? throw new \RuntimeException("unexpected service $id");
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (): void { $this->errors++; });
        $logger->method('warning')->willReturnCallback(function (): void { $this->warnings++; });
        return new GuestRestrictions($apps, $request,
            static fn () => $service('OCA\Guests\GuestManager'), static fn () => $service('OCA\Guests\AppWhitelist'),
            static fn () => $service('OCA\Guests\RestrictionManager'), static fn () => $service('OCA\Guests\Config'), $logger);
    }

    /** @internal answers of the Guests doubles, recorded in order */
    public function record(string $call): bool {
        $this->calls[] = $call;
        return match (true) {
            str_starts_with($call, 'isGuest:') => $this->isGuest,
            str_starts_with($call, 'isUrlAllowed:') => $this->urlAllowed,
            $call === 'isWhitelistEnabled' => $this->whitelistEnabled,
            $call === 'allowExternalStorage' => $this->externalAllowed,
            str_starts_with($call, 'isAppWhitelisted:') => in_array(substr($call, strlen('isAppWhitelisted:')), $this->whitelist, true),
            default => true,
        };
    }

    private function user(string $uid = 'guest'): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    public function testWithoutTheGuestsAppNothingIsAskedNorApplied(): void {
        $this->guestsEnabled = false;
        $this->assertTrue($this->restrictions()->apply($this->user()));
        $this->assertSame([], $this->calls);
    }

    public function testAnAccountThatIsNotAGuestIsLeftAlone(): void {
        $this->isGuest = false;
        $this->assertTrue($this->restrictions()->apply($this->user('alice')));
        $this->assertSame(['isGuest:alice'], $this->calls);
    }

    /** The same steps as Guests on a login: the whitelist check of the request, then the restrictions, late ones last. */
    public function testAGuestGetsTheWhitelistCheckAndEveryRestriction(): void {
        $this->assertTrue($this->restrictions()->apply($this->user()));
        $this->assertSame(['isGuest:guest', 'isUrlAllowed:/apps/mcp/', 'setupRestrictions:guest', 'lateSetupRestrictions'], $this->calls);
    }

    public function testAGuestOutsideTheWhitelistIsRefusedBeforeAnythingIsSetUp(): void {
        $this->urlAllowed = false;
        $this->assertFalse($this->restrictions()->apply($this->user()));
        $this->assertSame(['isGuest:guest', 'isUrlAllowed:/apps/mcp/'], $this->calls);
    }

    /** Guests enabled but its services unusable (another version, a failure inside): refused, never let through. */
    public function testAFailureInsideGuestsFailsClosed(): void {
        $this->failure = new \Error('Class "OCA\Guests\GuestManager" not found');
        $this->assertFalse($this->restrictions()->apply($this->user()));
        $this->assertSame(1, $this->errors);
    }

    /** The app of a module, as Guests would answer for a URL of that app; the core ('') is never asked about. */
    public function testAGuestReachesOnlyTheAppsOnTheGuestsList(): void {
        $restrictions = $this->restrictions();
        $this->assertTrue($restrictions->allowsApp('guest', 'calendar'));
        $this->assertFalse($restrictions->allowsApp('guest', 'notes'));
        $this->assertTrue($restrictions->allowsApp('guest', ''));
        $this->assertSame(['isGuest:guest', 'isWhitelistEnabled', 'isAppWhitelisted:calendar',
            'isGuest:guest', 'isWhitelistEnabled', 'isAppWhitelisted:notes'], $this->calls);
        $this->calls = [];
        $this->assertFalse($restrictions->allowsApp('guest', 'notes'), 'answered again from this request');
        $this->assertSame([], $this->calls);
    }

    public function testEveryAppIsAllowedWithoutGuestsToANonGuestOrWithTheListOff(): void {
        $this->guestsEnabled = false;
        $this->assertTrue($this->restrictions()->allowsApp('guest', 'notes'));
        $this->assertSame([], $this->calls);
        $this->guestsEnabled = true;
        $this->isGuest = false;
        $this->assertTrue($this->restrictions()->allowsApp('alice', 'notes'));
        $this->isGuest = true;
        $this->whitelistEnabled = false;
        $this->assertTrue($this->restrictions()->allowsApp('guest', 'notes'));
    }

    public function testAnUnreadableListRefusesTheApp(): void {
        $this->failure = new \RuntimeException('app config unreadable');
        $this->assertFalse($this->restrictions()->allowsApp('guest', 'calendar'));
        $this->assertSame(1, $this->errors);
    }

    /** A checkout link has no session: the app list and the storage filter apply to its owner, the late part does not. */
    public function testTheOwnerOfALinkGetsTheWhitelistCheckAndTheStorageFilter(): void {
        $this->assertTrue($this->restrictions()->applyToOwner($this->user()));
        $this->assertSame(['isGuest:guest', 'isUrlAllowed:/apps/mcp/', 'setupRestrictions:guest'], $this->calls);
    }

    public function testTheOwnerOfALinkOutsideTheWhitelistIsRefused(): void {
        $this->urlAllowed = false;
        $this->assertFalse($this->restrictions()->applyToOwner($this->user()));
        $this->assertSame(['isGuest:guest', 'isUrlAllowed:/apps/mcp/'], $this->calls);
    }

    /** A Guests that can only restrict the session user would hide nothing on a link: its guests are refused there. */
    public function testALegacyGuestsRefusesTheLinkOfAGuestButNotTheMcpRequest(): void {
        $this->legacyGuests = true;
        $this->assertFalse($this->restrictions()->applyToOwner($this->user()));
        $this->assertSame(1, $this->warnings);
        $this->assertNotContains('setupRestrictions:session', $this->calls);
        $this->calls = [];
        $this->assertTrue($this->restrictions()->apply($this->user()), 'with the user in the session, it applies');
        $this->assertSame(['isGuest:guest', 'isUrlAllowed:/apps/mcp/', 'setupRestrictions:session', 'lateSetupRestrictions'], $this->calls);
    }

    public function testTheOwnerOfALinkWhoIsNoGuestIsLeftAlone(): void {
        $this->isGuest = false;
        $this->assertTrue($this->restrictions()->applyToOwner($this->user('alice')));
        $this->assertSame(['isGuest:alice'], $this->calls);
    }

    /** External storage is hidden from a guest while Guests does not allow it, and from nobody else. */
    public function testExternalStorageIsHiddenFromAGuestWhileGuestsDoesNotAllowIt(): void {
        $this->assertTrue($this->restrictions()->hidesExternalStorage('guest'));
        $this->externalAllowed = true;
        $this->assertFalse($this->restrictions()->hidesExternalStorage('guest'));
        $this->externalAllowed = false;
        $this->isGuest = false;
        $this->assertFalse($this->restrictions()->hidesExternalStorage('alice'));
        $this->isGuest = true;
        $this->guestsEnabled = false;
        $this->assertFalse($this->restrictions()->hidesExternalStorage('guest'));
    }

    public function testAnUnreadableStorageSettingHidesIt(): void {
        $this->failure = new \RuntimeException('app config unreadable');
        $this->assertTrue($this->restrictions()->hidesExternalStorage('guest'));
        $this->assertSame(1, $this->errors);
    }
}
