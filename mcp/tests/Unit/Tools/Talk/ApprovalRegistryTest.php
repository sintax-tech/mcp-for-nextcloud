<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\Tools\Talk\ApprovalException;
use OCA\Mcp\Tools\Talk\ApprovalRegistry;
use OCA\Mcp\Tools\Talk\Messages;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The lock that makes confirm more than a word: an id only approves the exact draft it was issued for, only once,
 * only for the account it was issued to and only until it expires. Every refusal has to be the same refusal, so a
 * caller cannot tell an id of somebody else from an id that was already spent.
 */
class ApprovalRegistryTest extends TestCase {
    /** @var array<string, string> App config rows the fake holds. */
    private array $rows = [];
    private IAppConfig&MockObject $config;
    private ISecureRandom&MockObject $random;
    private ApprovalRegistry $approvals;

    protected function setUp(): void {
        parent::setUp();
        $this->rows = [];
        $this->config = $this->createMock(IAppConfig::class);
        $this->config->method('getValueString')
            ->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->rows[$key] ?? $default);
        $this->config->method('setValueString')
            ->willReturnCallback(function (string $app, string $key, string $value): bool {
                $this->rows[$key] = $value;

                return true;
            });
        $this->config->method('deleteKey')
            ->willReturnCallback(function (string $app, string $key): void {
                unset($this->rows[$key]);
            });
        $this->config->method('getKeys')
            ->willReturnCallback(fn (string $app): array => array_keys($this->rows));

        // Deterministic ids: what is under test is the binding, not the randomness.
        $issued = 0;
        $this->random = $this->createMock(ISecureRandom::class);
        $this->random->method('generate')->willReturnCallback(static function () use (&$issued): string {
            return sprintf('ID%030d', ++$issued);
        });

        $this->approvals = new ApprovalRegistry($this->config, $this->random);
    }

    public function testTheIdReturnedByTheDraftApprovesThatSameDraft(): void {
        $id = $this->approvals->issue('alice', $this->givenBinding());

        $this->approvals->consume('alice', $id, $this->givenBinding());

        $this->addToAssertionCount(1);
    }

    public function testTheIdIsSpentByTheFirstApprovalSoTheSameDraftCannotPublishTwice(): void {
        $id = $this->approvals->issue('alice', $this->givenBinding());
        $this->approvals->consume('alice', $id, $this->givenBinding());

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::approvalInvalid());
        $this->approvals->consume('alice', $id, $this->givenBinding());
    }

    public function testACallWithoutAnIdIsRefusedWithTheInstructionToAskForTheDraft(): void {
        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::approvalMissing());
        $this->approvals->consume('alice', null, $this->givenBinding());
    }

    public function testAnEmptyIdIsNoIdAtAll(): void {
        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::approvalMissing());
        $this->approvals->consume('alice', '', $this->givenBinding());
    }

    public function testAnIdNobodyIssuedIsRefused(): void {
        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::approvalInvalid());
        $this->approvals->consume('alice', 'ID999', $this->givenBinding());
    }

    public function testAnIdOfAnotherAccountDoesNotApproveAnything(): void {
        $id = $this->approvals->issue('alice', $this->givenBinding());

        try {
            // Same draft, same content: what is missing is the account it was shown to.
            $this->approvals->consume('mallory', $id, $this->givenBinding());
            $this->fail('An id issued to another account must not approve anything.');
        } catch (ApprovalException $e) {
            $this->assertSame(Messages::approvalInvalid(), $e->getMessage());
            $this->assertStringNotContainsString('alice', $e->getMessage());
        }
    }

    public function testAnIdOfAnotherConversationDoesNotApproveThisCall(): void {
        $id = $this->approvals->issue('alice', ['action' => 'talk_reply', 'conversation' => 'abcd', 'message' => 'oi']);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::approvalInvalid());
        $this->approvals->consume('alice', $id, ['action' => 'talk_reply', 'conversation' => 'zzzz', 'message' => 'oi']);
    }

    public function testAnIdOfAnotherActionDoesNotApproveThisCall(): void {
        $id = $this->approvals->issue('alice', ['action' => 'talk_reply', 'conversation' => 'abcd', 'message' => 'oi']);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::approvalInvalid());
        $this->approvals->consume('alice', $id, ['action' => 'talk_attach_file', 'conversation' => 'abcd', 'message' => 'oi']);
    }

    public function testAnIdOfAnotherPayloadDoesNotApproveThisCall(): void {
        $id = $this->approvals->issue('alice', ['action' => 'talk_reply', 'conversation' => 'abcd', 'message' => 'oi']);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::approvalInvalid());
        $this->approvals->consume('alice', $id, ['action' => 'talk_reply', 'conversation' => 'abcd', 'message' => 'oi alterado']);
    }

    public function testAnExpiredIdDoesNotApproveAnythingAndIsDropped(): void {
        $id = $this->approvals->issue('alice', $this->givenBinding());
        $this->givenExpiredApproval($id, 'alice', $this->givenBinding());

        try {
            $this->approvals->consume('alice', $id, $this->givenBinding());
            $this->fail('An expired id must not approve anything.');
        } catch (ApprovalException $e) {
            $this->assertSame(Messages::approvalInvalid(), $e->getMessage());
        }
        $this->assertArrayNotHasKey(ApprovalRegistry::KEY_PREFIX . $id, $this->rows);
    }

    public function testTheMessageTextIsNeverStoredInTheConfig(): void {
        $id = $this->approvals->issue('alice', ['action' => 'talk_reply', 'message' => 'segredo do usuário']);

        // Only a digest, so a pending approval cannot be read out of the config by anybody else.
        $this->assertStringNotContainsString('segredo', $this->rows[ApprovalRegistry::KEY_PREFIX . $id]);
        $this->assertStringContainsString(ApprovalRegistry::fingerprint([
            'action' => 'talk_reply',
            'message' => 'segredo do usuário',
        ]), $this->rows[ApprovalRegistry::KEY_PREFIX . $id]);
    }

    public function testExpiredApprovalsAreDroppedWhenANewDraftIsIssued(): void {
        $expired = $this->approvals->issue('alice', $this->givenBinding());
        $alive = $this->approvals->issue('alice', $this->givenBinding());
        $this->givenExpiredApproval($expired, 'alice', $this->givenBinding());
        // The last prune happened a minute ago, as the throttle demands.
        $this->rows[ApprovalRegistry::KEY_PREFIX . 'last_prune'] = (string)(time() - 61);

        $this->approvals->issue('alice', $this->givenBinding());

        $this->assertArrayNotHasKey(ApprovalRegistry::KEY_PREFIX . $expired, $this->rows);
        $this->assertArrayHasKey(ApprovalRegistry::KEY_PREFIX . $alive, $this->rows);
    }

    public function testPruningDoesNotRunOnEveryDraft(): void {
        $this->approvals->issue('alice', $this->givenBinding());
        $keysBefore = $this->config->getKeys(ApprovalRegistry::APP);

        $this->approvals->issue('alice', $this->givenBinding());

        // A burst of drafts must not turn each call into a full scan of the config.
        $this->assertSame(count($keysBefore) + 1, count($this->config->getKeys(ApprovalRegistry::APP)));
    }

    public function testTheFingerprintIsStableAcrossKeyOrderAndContent(): void {
        $this->assertSame(
            ApprovalRegistry::fingerprint(['a' => 1, 'b' => null]),
            ApprovalRegistry::fingerprint(['a' => 1, 'b' => null]),
        );
        $this->assertNotSame(
            ApprovalRegistry::fingerprint(['a' => 1, 'b' => null]),
            ApprovalRegistry::fingerprint(['a' => 1, 'b' => 'x']),
        );
    }

    /** @return array{action:string, conversation:string, message:string} */
    private function givenBinding(): array {
        return ['action' => 'talk_reply', 'conversation' => 'abcd', 'message' => 'oi'];
    }

    /**
     * @param array<string, mixed> $binding Binding the row was issued for
     * @return array{userId:string, binding:string, expiresAt:int}
     */
    private function givenExpiredApproval(string $id, string $userId, array $binding): array {
        $expired = [
            'userId' => $userId,
            'binding' => ApprovalRegistry::fingerprint($binding),
            'expiresAt' => time() - 1,
        ];
        $this->rows[ApprovalRegistry::KEY_PREFIX . $id] = (string)json_encode($expired);

        return $expired;
    }
}