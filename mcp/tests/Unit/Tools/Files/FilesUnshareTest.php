<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\IShare;

/**
 * files_unshare: removes a share the user created of a file the user owns, by id or by path and recipient, and
 * refuses everything else without telling the person what exists. Plan 0.10, decisions 3 and 8, invariant 5.
 */
final class FilesUnshareTest extends FilesToolsTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->tree->addFolder('/alice/files/Projetos');
        $this->sharePolicy->setGrant('alice', 'files', 'share', true);
    }

    /** @return int node id of a path of alice */
    private function id(string $path): int {
        return $this->tree->nodes['/alice/files' . $path]['id'];
    }

    /**
     * @param array<string, mixed> $options what {@see \OCA\Mcp\Tests\Unit\Tools\Files\Sharing\FakeShares::add()} takes
     * @return IShare a share of alice on /Documentos/ata.md unless the options say otherwise
     */
    private function share(array $options = []): IShare {
        return $this->shares->add($options + ['node' => $this->id('/Documentos/ata.md'), 'with' => 'bruno',
            'nodeObject' => $this->tree->node('/alice/files/Documentos/ata.md')]);
    }

    /** @return string the message of the ToolFailure of the plan */
    private function planFailure(array $arguments): string {
        try {
            $this->plan('files_unshare', $arguments);
        } catch (ToolFailure $e) {
            return $e->getMessage();
        }
        self::fail('the plan did not fail');
    }

    /** @return string the message of the ToolFailure of the confirmed call */
    private function confirmFailure(array $arguments): string {
        return $this->failure('files_unshare', $arguments + ['confirm' => true]);
    }

    /** @return array{field:string, rule:string} the details of the argument error */
    private function argumentError(array $arguments): array {
        try {
            $this->plan('files_unshare', $arguments);
        } catch (ArgumentValidationException $e) {
            return $e->details();
        }
        self::fail('no argument error');
    }

    private function confirm(array $arguments): array {
        return $this->json('files_unshare', $arguments + ['confirm' => true]);
    }

    /** Hides /Documentos/ata.md behind the administrator's hidden tag, the way the visibility contract does. */
    private function hideTheMinutes(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = new VisibilityGuard($this->config->mock($this), $tagMapper);
        $this->setUp();
        $tagMapper->method('getTagIdsForObjects')->willReturn([(string)$this->id('/Documentos/ata.md') => ['999']]);
    }

    public function testTheToolIsAWriteReachableWithShareOrLinkAndAllArgumentsAreOptional(): void {
        $definition = array_column($this->module->definitions(), null, 'name')['files_unshare'];
        self::assertSame(['files', 'share', ['share', 'link'], true],
            [$definition['module'], $definition['operation'], $definition['grantAnyOf'], $definition['destructiveHint']]);
        self::assertSame(['shareId', 'path', 'with'], array_keys($definition['inputSchema']['properties']));
        self::assertSame([], $definition['inputSchema']['required'] ?? []);
        self::assertStringContainsString('files_list_shares', $definition['description']);
    }

    public function testThePlanByIdNamesWhoLosesAccessAndRemovesNothing(): void {
        $share = $this->share();

        $plan = $this->plan('files_unshare', ['shareId' => $share->getFullId()]);

        self::assertSame($share->getFullId(), $plan['shareId']);
        self::assertSame('/Documentos/ata.md', $plan['path']);
        self::assertFalse($plan['isDir']);
        self::assertSame(['type' => 'user', 'id' => 'bruno', 'displayName' => 'Bruno Lima'], $plan['with']);
        self::assertSame([], $this->shares->writes);
        self::assertSame([$share], $this->shares->shares);
    }

    public function testConfirmingByIdDeletesTheShareAndTouchesNoFile(): void {
        $share = $this->share();
        $other = $this->share(['with' => 'carla']);

        $out = $this->confirm(['shareId' => $share->getFullId()]);

        self::assertSame([['delete', $share]], $this->shares->writes);
        self::assertSame([$other], $this->shares->shares, 'só o pedido some; o de Carla continua');
        self::assertSame([
            'removed' => 'ocinternal:1',
            'path' => '/Documentos/ata.md',
            'with' => ['type' => 'user', 'id' => 'bruno', 'displayName' => 'Bruno Lima'],
        ], $out);
        self::assertSame([], $this->tree->ops, 'nenhum delete nem move de arquivo');
        self::assertArrayHasKey('/alice/files/Documentos/ata.md', $this->tree->nodes);
    }

    public function testByPathAndPersonFindsTheShareOfThatPerson(): void {
        $this->share(['with' => 'carla']);
        $bruno = $this->share();

        $plan = $this->plan('files_unshare', ['path' => 'Documentos/ata.md', 'with' => 'user:bruno']);
        self::assertSame(['/Documentos/ata.md', $bruno->getFullId()], [$plan['path'], $plan['shareId']]);
        self::assertSame([], $this->shares->writes);

        $out = $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
        self::assertSame([['delete', $bruno]], $this->shares->writes);
        self::assertSame($bruno->getFullId(), $out['removed']);
        self::assertSame([], $this->tree->ops);
    }

    public function testByPathAndGroupRemovesTheGroupShareNotThePersonWithTheSameId(): void {
        $this->share(['with' => 'finance']);
        $group = $this->share(['type' => IShare::TYPE_GROUP, 'with' => 'finance']);

        $plan = $this->plan('files_unshare', ['path' => '/Documentos/ata.md', 'with' => 'group:finance']);
        self::assertSame(['type' => 'group', 'id' => 'finance', 'displayName' => 'Financeiro'], $plan['with']);

        $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'group:finance']);
        self::assertSame([['delete', $group]], $this->shares->writes);
    }

    public function testByPathAndLinkRemovesTheLink(): void {
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
        $link = $this->share(['type' => IShare::TYPE_LINK, 'with' => null, 'token' => 'TOK', 'password' => 'N3ver-Sh0wn#pass']);

        $plan = $this->plan('files_unshare', ['path' => '/Documentos/ata.md', 'with' => 'link']);
        self::assertSame('link', $plan['with']['type']);
        self::assertStringNotContainsString('N3ver-Sh0wn#pass', json_encode($plan));

        $out = $this->confirm(['path' => '/Documentos/ata.md', 'with' => 'link']);
        self::assertSame([['delete', $link]], $this->shares->writes);
        self::assertStringNotContainsString('N3ver-Sh0wn#pass', json_encode($out));
        self::assertStringNotContainsString('TOK', json_encode($out));
    }

    public function testTheShareOfAFolderIsMarkedAsOne(): void {
        $share = $this->shares->add(['node' => $this->id('/Projetos'), 'nodeType' => 'folder', 'with' => 'bruno',
            'nodeObject' => $this->tree->node('/alice/files/Projetos')]);
        self::assertTrue($this->plan('files_unshare', ['shareId' => $share->getFullId()])['isDir']);
    }

    public function testWithNothingMatchingByPathIsNotFound(): void {
        $this->share(['with' => 'carla']);
        self::assertSame(CommonMessages::notFound(), $this->planFailure(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']));
        self::assertSame(CommonMessages::notFound(), $this->confirmFailure(['path' => '/Documentos/ata.md', 'with' => 'group:finance']));
        self::assertSame([], $this->shares->writes);
    }

    /** Invariant 5 and decision 3: whatever is not the user's own answers the same, so nothing is revealed. */
    public function testWhatIsNotYoursIsIndistinguishableFromWhatDoesNotExist(): void {
        $this->tree->addFile('/alice/files/Recebido.pdf', 'x', 'application/pdf', ['scope' => 'shared']);
        $theirs = $this->share(['by' => 'carla', 'owner' => 'carla']);
        $ofSomeoneElsesFile = $this->shares->add(['node' => $this->id('/Recebido.pdf'), 'with' => 'bruno', 'owner' => 'alice',
            'nodeObject' => $this->tree->node('/alice/files/Recebido.pdf')]);
        $email = $this->share(['type' => IShare::TYPE_EMAIL, 'with' => 'x@example.org']);
        $talkOfSomeone = $this->share(['type' => IShare::TYPE_ROOM, 'with' => 'room1', 'by' => 'carla', 'owner' => 'carla']);
        $ids = [$theirs->getFullId(), $ofSomeoneElsesFile->getFullId(), $email->getFullId(), $talkOfSomeone->getFullId(),
            'ocinternal:9999', 'ocRoomShare:9999', 'sem-dois-pontos'];

        foreach ($ids as $id) {
            self::assertSame(CommonMessages::notFound(), $this->planFailure(['shareId' => $id]), 'plano de ' . $id);
            self::assertSame(CommonMessages::notFound(), $this->confirmFailure(['shareId' => $id]), 'confirmação de ' . $id);
        }
        self::assertSame([], $this->shares->writes);
        self::assertCount(4, $this->shares->shares);
    }

    /** The invariant literally: the node of the share must be owned by the user, even if the share names the user as owner. */
    public function testAShareWhoseNodeBelongsToSomebodyElseIsNotRemoved(): void {
        $this->tree->addFile('/alice/files/Recebido.pdf', 'x', 'application/pdf', ['scope' => 'shared']);
        $share = $this->shares->add(['node' => $this->id('/Recebido.pdf'), 'with' => 'bruno', 'by' => 'alice', 'owner' => 'alice',
            'nodeObject' => $this->tree->node('/alice/files/Recebido.pdf')]);

        self::assertSame(CommonMessages::notFound(), $this->confirmFailure(['shareId' => $share->getFullId()]));
        self::assertSame([], $this->shares->writes);
    }

    public function testATalkAttachmentOfYoursIsRefusedWithTheTalkMessage(): void {
        $room = $this->share(['type' => IShare::TYPE_ROOM, 'with' => 'room1', 'withName' => 'Diretoria']);

        self::assertSame(FilesMessages::shareTypeUnsupported(), $this->planFailure(['shareId' => $room->getFullId()]));
        self::assertSame(FilesMessages::shareTypeUnsupported(), $this->confirmFailure(['shareId' => $room->getFullId()]));
        self::assertSame([], $this->shares->writes);
    }

    public function testAHiddenNodeIsNotFoundByIdAndByPath(): void {
        $this->hideTheMinutes();
        $share = $this->share();

        self::assertSame(CommonMessages::notFound(), $this->planFailure(['shareId' => $share->getFullId()]));
        self::assertSame(CommonMessages::notFound(), $this->confirmFailure(['shareId' => $share->getFullId()]));
        self::assertSame(CommonMessages::notFound(), $this->confirmFailure(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']));
        self::assertSame([], $this->shares->writes);
    }

    public function testEachTypeNeedsItsOwnGrant(): void {
        $user = $this->share();
        $group = $this->share(['type' => IShare::TYPE_GROUP, 'with' => 'finance']);
        $link = $this->share(['type' => IShare::TYPE_LINK, 'with' => null, 'token' => 'T']);

        $this->sharePolicy->setGrant('alice', 'files', 'share', false);
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
        self::assertSame(FilesMessages::shareNotGranted(), $this->planFailure(['shareId' => $user->getFullId()]));
        self::assertSame(FilesMessages::shareNotGranted(), $this->planFailure(['shareId' => $group->getFullId()]));
        self::assertSame(FilesMessages::shareNotGranted(), $this->confirmFailure(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']));
        self::assertSame('link', $this->plan('files_unshare', ['shareId' => $link->getFullId()])['with']['type']);

        $this->sharePolicy->setGrant('alice', 'files', 'share', true);
        $this->sharePolicy->setGrant('alice', 'files', 'link', false);
        self::assertSame('user', $this->plan('files_unshare', ['shareId' => $user->getFullId()])['with']['type']);
        self::assertSame('group', $this->plan('files_unshare', ['shareId' => $group->getFullId()])['with']['type']);
        self::assertSame(FilesMessages::shareNotGranted(), $this->planFailure(['shareId' => $link->getFullId()]));
        self::assertSame(FilesMessages::shareNotGranted(), $this->confirmFailure(['path' => '/Documentos/ata.md', 'with' => 'link']));
        self::assertSame([], $this->shares->writes);
    }

    /** A missing grant must not tell a person that somebody else's link exists. */
    public function testTheGrantIsCheckedAfterOwnershipSoItRevealsNothing(): void {
        $theirLink = $this->share(['type' => IShare::TYPE_LINK, 'with' => null, 'token' => 'T', 'by' => 'carla', 'owner' => 'carla']);
        self::assertSame(CommonMessages::notFound(), $this->planFailure(['shareId' => $theirLink->getFullId()]));
    }

    public function testTheConfirmedCallChecksEverythingAgain(): void {
        $share = $this->share();
        $this->plan('files_unshare', ['shareId' => $share->getFullId()]);

        $this->sharePolicy->setGrant('alice', 'files', 'share', false);
        self::assertSame(FilesMessages::shareNotGranted(), $this->confirmFailure(['shareId' => $share->getFullId()]));

        $this->sharePolicy->setGrant('alice', 'files', 'share', true);
        $this->shares->shares = [];
        self::assertSame(CommonMessages::notFound(), $this->confirmFailure(['shareId' => $share->getFullId()]), 'já removido por outro caminho');
        self::assertSame([], $this->shares->writes);
    }

    public function testACoreRefusalIsTranslatedAndOnlyItsClassIsLogged(): void {
        $share = $this->share();
        $failure = new GenericShareException('Cannot delete /secret/path');
        $this->shares->failWrite = $failure;
        $this->shareLogger->expects(self::once())->method('warning')->with(self::anything(), self::callback(
            static fn (array $context): bool => $context === ['app' => 'mcp', 'exception_class' => $failure::class]));

        try {
            $this->confirm(['shareId' => $share->getFullId()]);
            self::fail('no failure');
        } catch (ToolFailure $e) {
            self::assertSame(FilesMessages::unshareRefused(), $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
        self::assertSame([], $this->tree->ops);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badTargets(): array {
        return [
            'nothing' => [[], 'shareId'],
            'id and path' => [['shareId' => 'ocinternal:1', 'path' => '/a.md'], 'shareId'],
            'id and with' => [['shareId' => 'ocinternal:1', 'with' => 'link'], 'shareId'],
            'path alone' => [['path' => '/Documentos/ata.md'], 'with'],
            'with alone' => [['with' => 'user:bruno'], 'path'],
        ];
    }

    /**
     * @dataProvider badTargets
     * @param array<string, string> $arguments the call
     * @param string $field the argument the error points at
     */
    public function testExactlyOneWayToNameTheShareIsAccepted(array $arguments, string $field): void {
        self::assertSame(['field' => $field, 'rule' => 'give either shareId, or path together with with'], $this->argumentError($arguments));
    }

    public function testAnUnknownRecipientIsAnArgumentErrorNamingNoValue(): void {
        self::assertSame(['field' => 'with', 'rule' => 'unknown account'], $this->argumentError(['path' => '/Documentos/ata.md', 'with' => 'user:ghost']));
        self::assertSame(['field' => 'with', 'rule' => 'expected user:<uid>, group:<gid> or link'],
            $this->argumentError(['path' => '/Documentos/ata.md', 'with' => 'bruno']));
    }

    public function testTheRootAndAMissingPathAreRefused(): void {
        self::assertSame(FilesMessages::shareRootRefused(), $this->planFailure(['path' => '/', 'with' => 'user:bruno']));
        self::assertSame(CommonMessages::notFound(), $this->planFailure(['path' => '/nada.md', 'with' => 'user:bruno']));
    }
}
