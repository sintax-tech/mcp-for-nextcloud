<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\Constants;
use OCP\Share\IShare;

/**
 * The confirmed files_share and files_unshare execute exactly what the plan showed. The plan carries `plan_state`, the
 * fingerprint of the action, the share and its fields before; the confirmed call gives it back, and when the share
 * changed in the meantime nothing is written and the answer is the new plan.
 */
final class FilesSharePlanStateTest extends FilesToolsTestCase {
    private const FILE = '/Documentos/ata.md';

    protected function setUp(): void {
        parent::setUp();
        $this->sharePolicy->setGrant('alice', 'files', 'share', true);
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
    }

    /** @param array<string, mixed> $options share options; of alice for bruno on the minutes unless they say otherwise */
    private function webShare(array $options = []): IShare {
        return $this->shares->add($options + ['node' => $this->tree->nodes['/alice/files' . self::FILE]['id'], 'with' => 'bruno',
            'nodeObject' => $this->tree->node('/alice/files' . self::FILE)]);
    }

    /** @return array<string, mixed> the plan the confirmed call answers with instead of writing */
    private function changed(string $tool, array $arguments, string $state): array {
        try {
            $this->module->call($tool, $this->validated($tool, $arguments + ['confirm' => true, PlanState::ARGUMENT => $state]), 'alice');
        } catch (PlanChanged $e) {
            return $e->plan;
        }
        self::fail('the confirmed call did not answer with a new plan');
    }

    private function assertChanged(array $plan): void {
        self::assertSame(FilesMessages::sharePlanChanged(), $plan['warnings'][0]['message'], 'o aviso vem primeiro');
        self::assertSame([], $this->shares->writes, 'nada é gravado');
    }

    public function testThePlanCarriesAnOpaqueState(): void {
        $plan = $this->plan('files_share', ['path' => self::FILE, 'with' => 'user:bruno']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan[PlanState::ARGUMENT]);
        self::assertSame($plan[PlanState::ARGUMENT], $this->plan('files_share', ['path' => self::FILE, 'with' => 'user:bruno'])[PlanState::ARGUMENT],
            'o mesmo estado dá o mesmo valor');
        self::assertSame(PlanState::property(), $this->validatedSchema('files_share')['properties'][PlanState::ARGUMENT]);
        self::assertSame(PlanState::property(), $this->validatedSchema('files_unshare')['properties'][PlanState::ARGUMENT]);
    }

    /** The scenario of the review: the plan creates, a web share with SHARE appears, the confirm must not take SHARE away. */
    public function testCreateThatBecameAnUpdateWritesNothingAndShowsTheNewPlan(): void {
        $arguments = ['path' => self::FILE, 'with' => 'user:bruno'];
        $state = $this->plan('files_share', $arguments)[PlanState::ARGUMENT];
        $this->webShare(['permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_SHARE]);

        $plan = $this->changed('files_share', $arguments, $state);
        $this->assertChanged($plan);
        self::assertSame('update', $plan['action']);
        self::assertTrue($plan['before']['reshare'], 'o novo plano mostra o direito de repasse que se perde');
        self::assertNotSame($state, $plan[PlanState::ARGUMENT]);

        $this->module->call('files_share', $this->validated('files_share', $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]]), 'alice');
        self::assertSame('update', $this->shares->writes[0][0], 'aprovado de novo, aí sim grava');
    }

    public function testUpdateThatBecameACreate(): void {
        $share = $this->webShare(['permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE]);
        $arguments = ['path' => self::FILE, 'with' => 'user:bruno'];
        $state = $this->plan('files_share', $arguments)[PlanState::ARGUMENT];
        $this->shares->shares = array_values(array_filter($this->shares->shares, static fn (IShare $s): bool => $s !== $share));

        $plan = $this->changed('files_share', $arguments, $state);
        $this->assertChanged($plan);
        self::assertSame('create', $plan['action']);
        self::assertNull($plan['shareId']);
    }

    public function testUpdateThatBecameNothing(): void {
        $share = $this->webShare();
        $arguments = ['path' => self::FILE, 'with' => 'user:bruno', 'permission' => 'edit'];
        $state = $this->plan('files_share', $arguments)[PlanState::ARGUMENT];
        $share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

        $plan = $this->changed('files_share', $arguments, $state);
        $this->assertChanged($plan);
        self::assertSame('none', $plan['action']);
    }

    public function testNothingThatBecameAnUpdate(): void {
        $share = $this->webShare(['permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE]);
        $arguments = ['path' => self::FILE, 'with' => 'user:bruno', 'permission' => 'edit'];
        $plan = $this->plan('files_share', $arguments);
        self::assertSame('none', $plan['action']);
        $share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_SHARE);

        $new = $this->changed('files_share', $arguments, $plan[PlanState::ARGUMENT]);
        $this->assertChanged($new);
        self::assertSame('update', $new['action']);
    }

    /** The same action on a share whose fields changed is another state too: the person approved other values. */
    public function testAnUpdateWhoseBeforeChanged(): void {
        $share = $this->webShare(['note' => 'antes']);
        $arguments = ['path' => self::FILE, 'with' => 'user:bruno', 'permission' => 'edit'];
        $state = $this->plan('files_share', $arguments)[PlanState::ARGUMENT];
        $share->setNote('mudou na web');

        $plan = $this->changed('files_share', $arguments, $state);
        $this->assertChanged($plan);
        self::assertSame('update', $plan['action']);
        self::assertSame('mudou na web', $plan['before']['note']);
    }

    /** The administrator starts requiring a password: the plan said none, so no password is generated without a new yes. */
    public function testALinkWhosePasswordPlanChangedGeneratesNoPassword(): void {
        $this->linkPasswords = ['Never-Generated#1'];
        $arguments = ['path' => self::FILE, 'with' => 'link'];
        $state = $this->plan('files_share', $arguments)[PlanState::ARGUMENT];
        $this->shares->settings['shareApiLinkEnforcePassword'] = true;

        $plan = $this->changed('files_share', $arguments, $state);
        $this->assertChanged($plan);
        self::assertSame('new', $plan['password']['after']);
        self::assertSame(['Never-Generated#1'], $this->linkPasswords, 'nenhuma senha foi gerada');
    }

    /** G4: a confirmed call without plan_state is an argument error on that field, and nothing is written. */
    public function testAConfirmWithoutPlanStateIsAnArgumentError(): void {
        $this->webShare(['note' => 'x']);
        foreach ([['files_share', ['path' => self::FILE, 'with' => 'user:bruno', 'permission' => 'edit']], ['files_unshare', ['path' => self::FILE, 'with' => 'user:bruno']]] as [$tool, $arguments]) {
            try {
                $this->module->call($tool, $this->validated($tool, $arguments + ['confirm' => true]), 'alice');
                self::fail("$tool confirmed without plan_state");
            } catch (ArgumentValidationException $e) {
                self::assertSame('plan_state', $e->details()['field']);
            }
        }
        self::assertSame([], $this->shares->writes);
        self::assertCount(1, $this->shares->shares);
    }

    /** A refusal still reads as before: plan_state is asked only of a call that could write. */
    public function testARefusalComesBeforeTheMissingPlanState(): void {
        $this->expectException(ToolFailure::class);
        $this->module->call('files_share', $this->validated('files_share', ['path' => '/nada.md', 'with' => 'user:bruno', 'confirm' => true]), 'alice');
    }

    public function testUnshareOfAShareThatChanged(): void {
        $share = $this->webShare();
        $arguments = ['shareId' => $share->getFullId()];
        $state = $this->plan('files_unshare', $arguments)[PlanState::ARGUMENT];
        $share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

        $plan = $this->changed('files_unshare', $arguments, $state);
        $this->assertChanged($plan);
        self::assertSame($share->getFullId(), $plan['shareId']);
        self::assertCount(1, $this->shares->shares, 'o compartilhamento continua lá');
    }

    /** By path and recipient, the share removed and made again is another share: the plan named the old one. */
    public function testUnshareOfAShareMadeAgain(): void {
        $old = $this->webShare();
        $arguments = ['path' => self::FILE, 'with' => 'user:bruno'];
        $state = $this->plan('files_unshare', $arguments)[PlanState::ARGUMENT];
        $this->shares->shares = [];
        $new = $this->webShare();

        $plan = $this->changed('files_unshare', $arguments, $state);
        $this->assertChanged($plan);
        self::assertSame($new->getFullId(), $plan['shareId']);
        self::assertNotSame($old->getFullId(), $plan['shareId']);
    }

    public function testUnshareOfAShareAlreadyRemovedIsNotFound(): void {
        $share = $this->webShare();
        $state = $this->plan('files_unshare', ['shareId' => $share->getFullId()])[PlanState::ARGUMENT];
        $this->shares->shares = [];

        self::assertSame(\OCA\Mcp\Tools\Common\CommonMessages::notFound(),
            $this->failure('files_unshare', ['shareId' => $share->getFullId(), 'confirm' => true, PlanState::ARGUMENT => $state]));
        self::assertSame([], $this->shares->writes);
    }

    public function testUnshareWithTheStateOfThePlanRemoves(): void {
        $share = $this->webShare();
        $state = $this->plan('files_unshare', ['shareId' => $share->getFullId()])[PlanState::ARGUMENT];
        $this->module->call('files_unshare', $this->validated('files_unshare', ['shareId' => $share->getFullId(), 'confirm' => true, PlanState::ARGUMENT => $state]), 'alice');
        self::assertSame('delete', $this->shares->writes[0][0]);
    }

    /** End to end through the registry the server runs: the plan, the line for the model, and the new plan on a change. */
    public function testThroughTheRegistry(): void {
        $registry = new ToolRegistry([$this->module], $this->sharePolicy, $this->apps, $this->users, $this->logger);
        $arguments = ['path' => self::FILE, 'with' => 'user:bruno'];

        $plan = $registry->call('files_share', $arguments, 'alice');
        $state = $plan['structuredContent'][PlanState::ARGUMENT];
        self::assertStringContainsString('repeat the call with plan_state `' . $state . '`', $plan['content'][0]['text']);

        $this->webShare(['permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_SHARE]);
        $again = $registry->call('files_share', $arguments + ['confirm' => true, PlanState::ARGUMENT => $state], 'alice');
        self::assertArrayNotHasKey('isError', $again);
        self::assertTrue($again['structuredContent']['requiresConfirmation']);
        self::assertSame('update', $again['structuredContent']['action']);
        self::assertStringContainsString(FilesMessages::sharePlanChanged(), $again['content'][0]['text']);
        $newState = $again['structuredContent'][PlanState::ARGUMENT];
        self::assertStringContainsString('repeat the call with plan_state `' . $newState . '`', $again['content'][0]['text']);
        self::assertSame([], $this->shares->writes);

        $done = $registry->call('files_share', $arguments + ['confirm' => true, PlanState::ARGUMENT => $newState], 'alice');
        self::assertArrayNotHasKey('isError', $done);
        self::assertSame('update', $done['structuredContent']['action']);
        self::assertTrue($done['structuredContent']['changed']);

        $missing = null;
        try {
            $registry->call('files_unshare', $arguments + ['confirm' => true], 'alice');
        } catch (ArgumentValidationException $e) {
            $missing = $e->details();
        }
        self::assertSame(['field' => 'plan_state', 'rule' => 'required with confirm: true; send the plan_state of the plan'], $missing);
    }

    /** @return array<string, mixed> the input schema of a tool as the module defines it */
    private function validatedSchema(string $tool): array {
        return array_column($this->module->definitions(), null, 'name')[$tool]['inputSchema'];
    }
}
