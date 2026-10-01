<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Constants;
use OCP\Share\Exceptions\AlreadySharedException;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\IShare;

/** files_share with a person or a group: the plan, the write, and every refusal before anything is written. */
final class FilesShareTest extends FilesToolsTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->tree->addFolder('/alice/files/Projetos');
        $this->sharePolicy->setGrant('alice', 'files', 'share', true);
    }

    private function id(string $path): int {
        return $this->tree->nodes['/alice/files' . $path]['id'];
    }

    /** @return string the message of the ToolFailure of the plan */
    private function planFailure(array $arguments): string {
        try {
            $this->plan('files_share', $arguments);
        } catch (ToolFailure $e) {
            return $e->getMessage();
        }
        self::fail('the plan did not fail');
    }

    /** @return array{field:string, rule:string} the details of the argument error of the plan */
    private function argumentError(array $arguments): array {
        try {
            $this->plan('files_share', $arguments);
        } catch (ArgumentValidationException $e) {
            return $e->details();
        }
        self::fail('no argument error');
    }

    private function confirm(array $arguments): array {
        return $this->json('files_share', $arguments + ['confirm' => true]);
    }

    public function testTheToolIsAWriteReachableWithShareOrLink(): void {
        $definition = array_column($this->module->definitions(), null, 'name')['files_share'];
        self::assertSame(['files', 'share', ['share', 'link'], true],
            [$definition['module'], $definition['operation'], $definition['grantAnyOf'], $definition['destructiveHint']]);
        self::assertSame(['path', 'with'], $definition['inputSchema']['required']);
        $properties = $definition['inputSchema']['properties'];
        self::assertSame(['view', 'edit'], $properties['permission']['enum']);
        self::assertSame('view', $properties['permission']['default']);
        self::assertSame(500, $properties['note']['maxLength']);
        self::assertSame(['path', 'with', 'permission', 'expires', 'note', 'password'], array_keys($properties));
    }

    public function testThePlanOfANewShareWritesNothing(): void {
        $plan = $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);

        self::assertSame('create', $plan['action']);
        self::assertSame('/Documentos/ata.md', $plan['path']);
        self::assertSame(['type' => 'user', 'id' => 'bruno', 'displayName' => 'Bruno Lima'], $plan['with']);
        self::assertNull($plan['before']);
        self::assertSame(['permission' => 'view', 'reshare' => false, 'expires' => null, 'note' => null], $plan['after']);
        self::assertTrue($plan['notifies']);
        self::assertSame([], $this->shares->writes);
    }

    public function testConfirmingCreatesTheShareThroughTheCore(): void {
        $out = $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'note' => 'Para revisar']);

        [[$operation, $share]] = $this->shares->writes;
        self::assertSame('create', $operation);
        self::assertSame(IShare::TYPE_USER, $share->getShareType());
        self::assertSame('bruno', $share->getSharedWith());
        self::assertSame('alice', $share->getSharedBy());
        self::assertSame(Constants::PERMISSION_READ, $share->getPermissions());
        self::assertSame('Para revisar', $share->getNote());
        self::assertSame($this->id('/Documentos/ata.md'), $share->getNodeId());
        self::assertSame('create', $out['action']);
        self::assertTrue($out['changed']);
        self::assertSame($share->getFullId(), $out['shareId']);
        self::assertSame(['id' => 'bruno', 'displayName' => 'Bruno Lima'], $out['with']);
        self::assertSame('view', $out['permission']);
        self::assertSame('/Documentos/ata.md', $out['path']);
    }

    public function testEditOnAFileIsReadAndUpdateAndOnAFolderAlsoCreateAndDelete(): void {
        $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'permission' => 'edit']);
        $this->confirm(['path' => '/Projetos', 'with' => 'user:bruno', 'permission' => 'edit']);

        self::assertSame(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE, $this->shares->writes[0][1]->getPermissions());
        self::assertSame(15, $this->shares->writes[1][1]->getPermissions());
        foreach ($this->shares->writes as [, $share]) {
            self::assertSame(0, $share->getPermissions() & Constants::PERMISSION_SHARE, 'nunca o bit SHARE');
        }
    }

    public function testAFolderWithEditWarnsThatTheRecipientCanDelete(): void {
        $plan = $this->plan('files_share', ['path' => '/Projetos', 'with' => 'user:bruno', 'permission' => 'edit']);
        self::assertTrue($plan['isDir']);
        self::assertContains(FilesMessages::shareFolderEditWarning('Bruno Lima'), array_column($plan['warnings'], 'message'));
        $plan = $this->plan('files_share', ['path' => '/Projetos', 'with' => 'user:bruno']);
        self::assertSame([], $plan['warnings']);
    }

    public function testAGroupShareReachesEveryMember(): void {
        $plan = $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'group:finance']);
        self::assertSame(['type' => 'group', 'id' => 'finance', 'displayName' => 'Financeiro'], $plan['with']);
        self::assertContains(FilesMessages::shareGroupWarning('Financeiro'), array_column($plan['warnings'], 'message'));

        $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'group:finance']);
        self::assertSame(IShare::TYPE_GROUP, $this->shares->writes[0][1]->getShareType());
        self::assertSame('finance', $this->shares->writes[0][1]->getSharedWith());
    }

    /** Decision 6: sharing again with the same person changes the existing share, here one made on the web. */
    public function testAnExistingShareIsUpdatedAndLosesTheReshareRight(): void {
        $existing = $this->shares->add(['node' => $this->id('/Documentos/ata.md'), 'with' => 'bruno', 'permissions' => 19,
            'nodeObject' => $this->tree->node('/alice/files/Documentos/ata.md')]);

        $plan = $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
        self::assertSame('update', $plan['action']);
        self::assertSame($existing->getFullId(), $plan['shareId']);
        self::assertSame(['permission' => 'edit', 'reshare' => true, 'expires' => null, 'note' => ''], $plan['before']);
        self::assertSame(['permission' => 'view', 'reshare' => false, 'expires' => null, 'note' => ''], $plan['after']);
        self::assertFalse($plan['notifies']);

        $out = $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
        self::assertSame([['update', $existing]], $this->shares->writes);
        self::assertSame(Constants::PERMISSION_READ, $existing->getPermissions());
        self::assertSame(['update', true, false], [$out['action'], $out['changed'], $out['reshare']]);
    }

    /** The same person, level, validity and note: nothing to change, and the confirmed call writes nothing. */
    public function testTheSameShareAgainIsNothingToChange(): void {
        $this->shares->add(['node' => $this->id('/Documentos/ata.md'), 'with' => 'bruno', 'permissions' => 1, 'note' => 'oi',
            'expires' => new \DateTime('2026-12-31 00:00:00'), 'nodeObject' => $this->tree->node('/alice/files/Documentos/ata.md')]);
        $arguments = ['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '2026-12-31', 'note' => 'oi'];

        self::assertSame('none', $this->plan('files_share', $arguments)['action']);
        self::assertSame('none', $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno'])['action'],
            'validade e nota omitidas ficam como estão');
        $out = $this->confirm($arguments);
        self::assertSame([], $this->shares->writes, 'nada a alterar não chama updateShare');
        self::assertSame(['none', false], [$out['action'], $out['changed']]);
    }

    public function testAChangedNoteOrValidityIsAnUpdate(): void {
        $existing = $this->shares->add(['node' => $this->id('/Documentos/ata.md'), 'with' => 'bruno', 'note' => 'oi',
            'nodeObject' => $this->tree->node('/alice/files/Documentos/ata.md')]);
        $plan = $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'note' => 'nova', 'expires' => '2026-10-10']);
        self::assertSame('update', $plan['action']);
        self::assertSame(['permission' => 'view', 'reshare' => false, 'expires' => '2026-10-10', 'note' => 'nova'], $plan['after']);

        $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'note' => 'nova', 'expires' => '2026-10-10']);
        self::assertSame('nova', $existing->getNote());
        self::assertSame('2026-10-10', $existing->getExpirationDate()->format('Y-m-d'));
    }

    public function testAnotherRecipientIsANewShareNotAnUpdate(): void {
        $this->shares->add(['node' => $this->id('/Documentos/ata.md'), 'with' => 'carla']);
        $this->shares->add(['node' => $this->id('/Documentos/ata.md'), 'with' => 'finance']);
        self::assertSame('create', $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno'])['action']);
        self::assertSame('create', $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'group:finance'])['action'],
            'um compartilhamento com a conta "finance" não é o do grupo');
    }

    public function testExpiresIsADateOfTheUserFromTomorrowOn(): void {
        self::assertSame(['field' => 'expires', 'rule' => 'expected a date as YYYY-MM-DD'],
            $this->argumentError(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '31/12/2026']));
        self::assertSame(['field' => 'expires', 'rule' => 'expected a date as YYYY-MM-DD'],
            $this->argumentError(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '2026-02-30']));
        // The clock is 2026-09-21 14:13 UTC: today cannot be the last day of a new share.
        self::assertSame(['field' => 'expires', 'rule' => 'tomorrow or later'],
            $this->argumentError(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '2026-09-21']));
        self::assertSame('2026-09-22', $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '2026-09-22'])['after']['expires']);

        // In Kiribati it is already 2026-09-22, so that day is today there.
        $this->config->user['alice']['core']['timezone'] = 'Pacific/Kiritimati';
        self::assertSame(['field' => 'expires', 'rule' => 'tomorrow or later'],
            $this->argumentError(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '2026-09-22']));
        self::assertSame('Pacific/Kiritimati', $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno'])['timezone']);
    }

    /** The core moves the date to the server zone and to 23:59:59; noon of the user's day keeps the same date. */
    public function testTheCoreReceivesNoonOfTheChosenDayInTheUsersZone(): void {
        $this->config->user['alice']['core']['timezone'] = 'Asia/Tokyo';
        $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '2026-10-05']);
        $date = $this->shares->writes[0][1]->getExpirationDate();
        self::assertSame('2026-10-05 12:00:00 Asia/Tokyo', $date->format('Y-m-d H:i:s e'));
        self::assertSame('2026-10-05', (clone $date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'));
    }

    public function testTheAdministratorsMaximumValidityIsEnforcedBeforeTheCore(): void {
        $this->shares->settings['shareApiInternalDefaultExpireDateEnforced'] = true;
        $this->shares->settings['shareApiInternalDefaultExpireDate'] = true;
        $this->shares->settings['shareApiInternalDefaultExpireDays'] = 7;
        self::assertSame(['field' => 'expires', 'rule' => 'at most 7 days from today (administrator rule)'],
            $this->argumentError(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '2026-09-29']));
        self::assertSame('2026-09-28', $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '2026-09-28'])['after']['expires']);
    }

    public function testWithoutExpiresANewShareGetsTheAdministratorsDefault(): void {
        $plan = $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
        self::assertSame('none', $plan['expiresSource']);
        $this->shares->settings['shareApiInternalDefaultExpireDate'] = true;
        $this->shares->settings['shareApiInternalDefaultExpireDays'] = 10;
        $plan = $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
        self::assertSame(['default', '2026-10-01'], [$plan['expiresSource'], $plan['after']['expires']]);
        $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
        self::assertNull($this->shares->writes[0][1]->getExpirationDate(), 'o core aplica o padrão; o app não o inventa');
    }

    public function testSharingWithYourselfIsRefused(): void {
        self::assertSame(FilesMessages::shareWithSelf(), $this->planFailure(['path' => '/Documentos/ata.md', 'with' => 'user:alice']));
    }

    public function testAnUnknownRecipientIsAnArgumentError(): void {
        self::assertSame(['field' => 'with', 'rule' => 'unknown account'], $this->argumentError(['path' => '/Documentos/ata.md', 'with' => 'user:ghost']));
        self::assertSame(['field' => 'with', 'rule' => 'unknown group'], $this->argumentError(['path' => '/Documentos/ata.md', 'with' => 'group:ghost']));
    }

    public function testANodeOfAnotherOwnerIsRefused(): void {
        $this->tree->addFile('/alice/files/Recebido.pdf', 'x', 'application/pdf', ['scope' => 'shared']);
        self::assertSame(FilesMessages::shareNotOwner(), $this->planFailure(['path' => '/Recebido.pdf', 'with' => 'user:bruno']));
    }

    public function testWithoutTheShareGrantNothingIsPlanned(): void {
        $this->sharePolicy->setGrant('alice', 'files', 'share', false);
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
        self::assertSame(FilesMessages::shareNotGranted(), $this->planFailure(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']));
    }

    /** A link answers to its own grant, not to `share`; the link behaviour itself is in FilesShareLinkTest. */
    public function testALinkNeedsTheLinkGrant(): void {
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
        self::assertSame('create', $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'link'])['action']);
        $this->sharePolicy->setGrant('alice', 'files', 'link', false);
        self::assertSame(FilesMessages::shareNotGranted(), $this->planFailure(['path' => '/Documentos/ata.md', 'with' => 'link']));
    }

    /** @return array<string, array{\Closure(self):void, string, string}> */
    public static function serverRules(): array {
        return [
            'sharing off' => [static fn (self $t) => $t->shares->settings['shareApiEnabled'] = false, 'user:bruno', 'shareDisabled'],
            'off for the user' => [static fn (self $t) => $t->shares->sharingDisabledFor = ['alice'], 'user:bruno', 'shareDisabledForYou'],
            'groups off' => [static fn (self $t) => $t->shares->settings['allowGroupSharing'] = false, 'group:finance', 'shareGroupsDisabled'],
            'only group members' => [static fn (self $t) => $t->shares->settings['shareWithGroupMembersOnly'] = true, 'user:carla', 'shareOnlyGroupMembers'],
            'only own groups' => [static fn (self $t) => $t->shares->settings['shareWithGroupMembersOnly'] = true, 'group:board', 'shareOnlyOwnGroups'],
            'excluded common group' => [static function (self $t): void {
                $t->shares->settings['shareWithGroupMembersOnly'] = true;
                $t->shares->settings['shareWithGroupMembersOnlyExcludeGroupsList'] = ['finance'];
            }, 'user:bruno', 'shareOnlyGroupMembers'],
        ];
    }

    /** @dataProvider serverRules */
    public function testTheAdministratorsRulesAreExplainedBeforeTheCoreRefuses(\Closure $rule, string $with, string $message): void {
        $rule($this);
        self::assertSame(FilesMessages::$message(), $this->planFailure(['path' => '/Documentos/ata.md', 'with' => $with]));
    }

    public function testMembersOnlyStillAllowsACommonGroup(): void {
        $this->shares->settings['shareWithGroupMembersOnly'] = true;
        self::assertSame('create', $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno'])['action']);
        self::assertSame('create', $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'group:finance'])['action']);
    }

    public function testANodeTheCoreWouldNotShareIsRefused(): void {
        $this->tree->addFile('/alice/files/Travado.md', 'x', 'text/markdown', ['shareable' => false]);
        self::assertSame(FilesMessages::shareNodeNotShareable(), $this->planFailure(['path' => '/Travado.md', 'with' => 'user:bruno']));
    }

    /** The core refuses more than the sharer has; the plan says it first, in words. */
    public function testEditOnAReadOnlyNodeIsRefused(): void {
        $this->tree->addFile('/alice/files/SoLeitura.md', 'x', 'text/markdown', ['permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_SHARE]);
        self::assertSame(FilesMessages::shareAboveOwnPermissions(), $this->planFailure(['path' => '/SoLeitura.md', 'with' => 'user:bruno', 'permission' => 'edit']));
        self::assertSame('create', $this->plan('files_share', ['path' => '/SoLeitura.md', 'with' => 'user:bruno'])['action']);
    }

    /** @return array<string, array{\Throwable, string}> */
    public static function coreFailures(): array {
        return [
            'generic' => [new GenericShareException('Cannot increase permissions of /secret/path'), 'shareRefused'],
            'invalid' => [new \InvalidArgumentException('Share recipient is not a valid user'), 'shareRefused'],
            'plain' => [new \Exception('Sharing is only allowed with group members'), 'shareRefused'],
        ];
    }

    /** @dataProvider coreFailures */
    public function testACoreRefusalIsTranslatedAndOnlyItsClassIsLogged(\Throwable $failure, string $message): void {
        $this->shares->failWrite = $failure;
        $this->shareLogger->expects(self::once())->method('warning')->with(self::anything(), self::callback(
            static fn (array $context): bool => $context === ['app' => 'mcp', 'exception_class' => $failure::class]));
        try {
            $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
            self::fail('no failure');
        } catch (ToolFailure $e) {
            self::assertSame(FilesMessages::$message(), $e->getMessage());
            self::assertStringNotContainsString($failure->getMessage(), $e->getMessage());
        }
    }

    public function testAnAlreadySharedRefusalSaysTheRecipientHasAccess(): void {
        $existing = $this->shares->add(['node' => $this->id('/Documentos/ata.md'), 'with' => 'bruno', 'permissions' => 3,
            'nodeObject' => $this->tree->node('/alice/files/Documentos/ata.md')]);
        $this->shares->failWrite = new AlreadySharedException('Sharing ata.md failed, because this item is already shared with the account Bruno', $existing);
        try {
            $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
            self::fail('no failure');
        } catch (ToolFailure $e) {
            self::assertSame(FilesMessages::shareAlreadyHasAccess(), $e->getMessage());
        }
    }

    /** Invariant 1: the confirmed call checks everything again, here a grant revoked after the plan. */
    public function testTheConfirmedCallChecksAgain(): void {
        $this->plan('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
        $this->sharePolicy->setGrant('alice', 'files', 'share', false);
        try {
            $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
            self::fail('no failure');
        } catch (ToolFailure $e) {
            self::assertSame(FilesMessages::shareNotGranted(), $e->getMessage());
        }
        self::assertSame([], $this->shares->writes);
    }
}
