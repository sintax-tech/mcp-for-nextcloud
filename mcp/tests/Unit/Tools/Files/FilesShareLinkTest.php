<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tests\Unit\Tools\Files\Sharing\FakeShares;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\Sharing\ShareFormatter;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Constants;
use OCP\Share\IShare;

/** files_share with `with: "link"`: one public link per file, read only, a server-made password shown once. */
final class FilesShareLinkTest extends FilesToolsTestCase {
    private const PASSWORD = 'Kn0wn-Generated#Pass';
    private const OTHER = 'Second-Generated#Pass2';
    private const FILE = '/Documentos/ata.md';

    protected function setUp(): void {
        parent::setUp();
        $this->tree->addFolder('/alice/files/Projetos');
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
    }

    private function id(string $path): int {
        return $this->tree->nodes['/alice/files' . $path]['id'];
    }

    /** @return IShare a link alice already has on the file */
    private function existingLink(array $o = []): IShare {
        return $this->shares->add($o + ['type' => IShare::TYPE_LINK, 'with' => null, 'node' => $this->id(self::FILE),
            'nodeObject' => $this->tree->node('/alice/files' . self::FILE), 'token' => 'oldtok']);
    }

    /** @return array<string, mixed> the confirmed call as the client receives it: text and structuredContent */
    private function confirmed(array $arguments): array {
        return $this->tool('files_share', $arguments + ['confirm' => true]);
    }

    /** @return array<string, mixed> the decoded result of the confirmed call */
    private function confirm(array $arguments): array {
        return json_decode($this->confirmed($arguments)['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function refusal(string $name, array $arguments): string {
        try {
            $name === 'plan' ? $this->plan('files_share', $arguments) : $this->confirm($arguments);
        } catch (ToolFailure $e) {
            return $e->getMessage();
        }
        self::fail('no failure');
    }

    /** @return array{field:string, rule:string} */
    private function argumentError(array $arguments): array {
        try {
            $this->plan('files_share', $arguments);
        } catch (ArgumentValidationException $e) {
            return $e->details();
        }
        self::fail('no argument error');
    }

    public function testPasswordIsAnOptionalBooleanOfTheSchema(): void {
        $properties = array_column($this->module->definitions(), null, 'name')['files_share']['inputSchema']['properties'];
        self::assertSame('boolean', $properties['password']['type']);
        self::assertStringContainsString('link', $properties['password']['description']);
    }

    public function testThePlanOfANewLinkWritesNothingAndPromisesNoPassword(): void {
        $plan = $this->plan('files_share', ['path' => self::FILE, 'with' => 'link']);
        self::assertSame('create', $plan['action']);
        self::assertSame('link', $plan['with']['type']);
        self::assertSame(['before' => null, 'after' => 'none', 'required' => false], $plan['password']);
        self::assertSame([], $this->shares->writes);
    }

    public function testConfirmingCreatesAReadOnlyLinkWithItsPublicUrl(): void {
        $out = $this->confirm(['path' => self::FILE, 'with' => 'link']);

        [[$kind, $share]] = $this->shares->writes;
        self::assertSame('create', $kind);
        self::assertSame(IShare::TYPE_LINK, $share->getShareType());
        self::assertSame(Constants::PERMISSION_READ, $share->getPermissions(), 'invariante 4');
        self::assertNull($share->getSharedWith());
        self::assertSame('alice', $share->getSharedBy());
        self::assertNull($share->getPassword());
        self::assertSame(['create', true, 'link', 'view', false], [$out['action'], $out['changed'], $out['type'], $out['permission'], $out['hasPassword']]);
        self::assertSame([$share->getToken()], $this->issued[ShareFormatter::LINK_ROUTE]);
        self::assertSame('https://cloud.test/apps/mcp/' . $share->getToken(), $out['url']);
        self::assertArrayNotHasKey('password', $out);
    }

    public function testPasswordTrueGeneratesItAndShowsItOnceInTextAndStructuredContent(): void {
        $this->linkPasswords = [self::PASSWORD];
        $result = $this->confirmed(['path' => self::FILE, 'with' => 'link', 'password' => true]);

        $out = json_decode($result['content'][0]['text'], true);
        self::assertSame(self::PASSWORD, $out['password']);
        self::assertSame(self::PASSWORD, $result['structuredContent']['password']);
        self::assertSame(FilesMessages::linkPasswordShownOnce(), $out['passwordNotice']);
        self::assertTrue($out['hasPassword']);
        [[, $share]] = $this->shares->writes;
        self::assertSame(FakeShares::hashOf(self::PASSWORD), $share->getPassword(), 'o core recebe a senha e guarda o hash');
    }

    public function testAPasswordEnforcedByTheAdministratorIsGeneratedWithoutAsking(): void {
        $this->shares->settings['shareApiLinkEnforcePassword'] = true;
        $this->linkPasswords = [self::PASSWORD];
        $plan = $this->plan('files_share', ['path' => self::FILE, 'with' => 'link']);
        self::assertSame(['before' => null, 'after' => 'new', 'required' => true], $plan['password']);

        $this->linkPasswords = [self::PASSWORD];
        self::assertSame(self::PASSWORD, $this->confirm(['path' => self::FILE, 'with' => 'link'])['password']);
    }

    public function testAPolicyThatRefusesEveryPasswordCreatesNoLink(): void {
        $this->refuseAllPasswords = true;
        self::assertSame(FilesMessages::linkPasswordRefused(), $this->refusal('confirm', ['path' => self::FILE, 'with' => 'link', 'password' => true]));
        self::assertSame([], $this->shares->writes);
    }

    public function testLinksTurnedOffByTheAdministratorAreRefusedBeforeTheCore(): void {
        $this->shares->settings['shareApiAllowLinks'] = false;
        self::assertSame(FilesMessages::shareLinksDisabled(), $this->refusal('plan', ['path' => self::FILE, 'with' => 'link']));
    }

    /** Invariant 4: a link never edits. */
    public function testEditOnALinkIsAnArgumentError(): void {
        self::assertSame(['field' => 'permission', 'rule' => 'only view for a public link'],
            $this->argumentError(['path' => '/Projetos', 'with' => 'link', 'permission' => 'edit']));
    }

    public function testWithoutTheLinkGrantNothingIsPlanned(): void {
        $this->sharePolicy->setGrant('alice', 'files', 'link', false);
        $this->sharePolicy->setGrant('alice', 'files', 'share', true);
        self::assertSame(FilesMessages::shareNotGranted(), $this->refusal('plan', ['path' => self::FILE, 'with' => 'link']));
    }

    public function testANodeOfAnotherOwnerIsRefused(): void {
        $this->tree->addFile('/alice/files/Recebido.pdf', 'x', 'application/pdf', ['scope' => 'shared']);
        self::assertSame(FilesMessages::shareNotOwner(), $this->refusal('plan', ['path' => '/Recebido.pdf', 'with' => 'link']));
    }

    /** One link per file: asking again changes the one that exists. */
    public function testAnExistingLinkIsUpdatedNotDuplicated(): void {
        $link = $this->existingLink();
        $plan = $this->plan('files_share', ['path' => self::FILE, 'with' => 'link', 'expires' => '2026-10-10']);
        self::assertSame(['update', $link->getFullId()], [$plan['action'], $plan['shareId']]);

        $out = $this->confirm(['path' => self::FILE, 'with' => 'link', 'expires' => '2026-10-10']);
        self::assertSame([['update', $link]], $this->shares->writes);
        self::assertSame('2026-10-10', $out['expires']);
        self::assertSame('https://cloud.test/apps/mcp/oldtok', $out['url']);
    }

    public function testTheSameLinkAgainIsNothingToChange(): void {
        $this->existingLink();
        self::assertSame('none', $this->plan('files_share', ['path' => self::FILE, 'with' => 'link'])['action']);
        self::assertFalse($this->confirm(['path' => self::FILE, 'with' => 'link'])['changed']);
        self::assertSame([], $this->shares->writes);
    }

    /** A web-made link carries SHARE (outgoing federation); the tool neither adds nor needlessly rewrites it. */
    public function testAWebMadeLinkKeepsItsBitsWhenOnlyTheValidityChanges(): void {
        $link = $this->existingLink(['permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_SHARE]);
        self::assertSame('none', $this->plan('files_share', ['path' => self::FILE, 'with' => 'link'])['action']);
        $this->confirm(['path' => self::FILE, 'with' => 'link', 'expires' => '2026-10-10']);
        self::assertSame(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE, $link->getPermissions());
    }

    /** A folder link that allowed uploads comes back to read only. */
    public function testAnUploadLinkIsBroughtBackToReadOnly(): void {
        $link = $this->shares->add(['type' => IShare::TYPE_LINK, 'with' => null, 'node' => $this->id('/Projetos'), 'nodeType' => 'folder',
            'nodeObject' => $this->tree->node('/alice/files/Projetos'), 'token' => 't', 'permissions' => 15]);
        $plan = $this->plan('files_share', ['path' => '/Projetos', 'with' => 'link']);
        self::assertSame(['update', 'edit', 'view'], [$plan['action'], $plan['before']['permission'], $plan['after']['permission']]);
        $this->confirm(['path' => '/Projetos', 'with' => 'link']);
        self::assertSame(Constants::PERMISSION_READ, $link->getPermissions());
    }

    public function testPasswordTrueOnAnExistingLinkReplacesItAndShowsTheNewOneOnce(): void {
        $link = $this->existingLink(['password' => FakeShares::hashOf('old-secret')]);
        $this->linkPasswords = [self::PASSWORD];
        $plan = $this->plan('files_share', ['path' => self::FILE, 'with' => 'link', 'password' => true]);
        self::assertSame(['update', ['before' => true, 'after' => 'new', 'required' => false]], [$plan['action'], $plan['password']]);

        $out = $this->confirm(['path' => self::FILE, 'with' => 'link', 'password' => true]);
        self::assertSame(self::PASSWORD, $out['password']);
        self::assertSame(FakeShares::hashOf(self::PASSWORD), $link->getPassword());
    }

    public function testWithoutPasswordTrueAnUpdateKeepsThePasswordAndNeverShowsIt(): void {
        $link = $this->existingLink(['password' => FakeShares::hashOf('old-secret')]);
        $plan = $this->plan('files_share', ['path' => self::FILE, 'with' => 'link', 'expires' => '2026-10-10']);
        self::assertSame(['before' => true, 'after' => 'kept', 'required' => false], $plan['password']);

        $out = $this->confirm(['path' => self::FILE, 'with' => 'link', 'expires' => '2026-10-10']);
        self::assertArrayNotHasKey('password', $out);
        self::assertTrue($out['hasPassword']);
        self::assertSame(FakeShares::hashOf('old-secret'), $link->getPassword());
        self::assertStringNotContainsString('old-secret', json_encode($out));
    }

    /** The administrator started to require passwords after the link was made: the update gives it one. */
    public function testALinkWithoutPasswordGetsOneWhenTheAdministratorNowRequiresIt(): void {
        $link = $this->existingLink();
        $this->shares->settings['shareApiLinkEnforcePassword'] = true;
        $plan = $this->plan('files_share', ['path' => self::FILE, 'with' => 'link']);
        self::assertSame(['update', ['before' => false, 'after' => 'new', 'required' => true]], [$plan['action'], $plan['password']]);

        $this->linkPasswords = [self::PASSWORD];
        self::assertSame(self::PASSWORD, $this->confirm(['path' => self::FILE, 'with' => 'link'])['password']);
        self::assertSame(FakeShares::hashOf(self::PASSWORD), $link->getPassword());
    }

    public function testTheAdministratorsMaximumValidityOfLinksIsEnforcedBeforeTheCore(): void {
        $this->shares->settings['shareApiLinkDefaultExpireDateEnforced'] = true;
        $this->shares->settings['shareApiLinkDefaultExpireDays'] = 7;
        self::assertSame(['field' => 'expires', 'rule' => 'at most 7 days from today (administrator rule)'],
            $this->argumentError(['path' => self::FILE, 'with' => 'link', 'expires' => '2026-09-29']));
        self::assertSame('2026-09-28', $this->plan('files_share', ['path' => self::FILE, 'with' => 'link', 'expires' => '2026-09-28'])['after']['expires']);
    }

    /** The internal maximum is not the link maximum: each kind of share follows its own rule. */
    public function testTheInternalMaximumDoesNotLimitLinks(): void {
        $this->shares->settings['shareApiInternalDefaultExpireDateEnforced'] = true;
        $this->shares->settings['shareApiInternalDefaultExpireDays'] = 3;
        self::assertSame('2026-12-31', $this->plan('files_share', ['path' => self::FILE, 'with' => 'link', 'expires' => '2026-12-31'])['after']['expires']);
    }

    public function testWithoutExpiresANewLinkGetsTheAdministratorsDefault(): void {
        $this->shares->settings['shareApiLinkDefaultExpireDate'] = true;
        $this->shares->settings['shareApiLinkDefaultExpireDays'] = 14;
        $plan = $this->plan('files_share', ['path' => self::FILE, 'with' => 'link']);
        self::assertSame(['2026-10-05', 'default'], [$plan['after']['expires'], $plan['expiresSource']]);
    }

    /** Enforced without a default would make the core refuse an empty date; the tool sends the maximum instead. */
    public function testAnEnforcedValidityWithoutDefaultIsSentExplicitly(): void {
        $this->shares->settings['shareApiLinkDefaultExpireDateEnforced'] = true;
        $this->shares->settings['shareApiLinkDefaultExpireDays'] = 7;
        $this->confirm(['path' => self::FILE, 'with' => 'link']);
        [[, $share]] = $this->shares->writes;
        self::assertSame('2026-09-28', $share->getExpirationDate()?->format('Y-m-d'));
    }

    public function testThePasswordArgumentMeansNothingForPeople(): void {
        $this->sharePolicy->setGrant('alice', 'files', 'share', true);
        self::assertSame(['field' => 'password', 'rule' => 'only for a public link'],
            $this->argumentError(['path' => self::FILE, 'with' => 'user:bruno', 'password' => true]));
    }

    /** Invariant 1: a grant revoked between the plan and the confirmation stops the link. */
    public function testTheConfirmedCallChecksAgain(): void {
        $this->plan('files_share', ['path' => self::FILE, 'with' => 'link']);
        $this->sharePolicy->setGrant('alice', 'files', 'link', false);
        self::assertSame(FilesMessages::shareNotGranted(), $this->refusal('confirm', ['path' => self::FILE, 'with' => 'link']));
        self::assertSame([], $this->shares->writes);
    }
}
