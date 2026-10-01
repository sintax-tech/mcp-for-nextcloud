<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\PlanState;
use PHPUnit\Framework\TestCase;

/** The opaque state of a plan: stable for the same state, different for any other, and keyed by the instance secret. */
final class PlanStateTest extends TestCase {
    private function states(string $secret = 'instance-secret'): PlanState {
        $config = new InMemoryConfig();
        $config->system['secret'] = $secret;
        return new PlanState($config->mock($this));
    }

    public function testTheSameStateGivesTheSameValueAcrossInstances(): void {
        $shown = ['action' => 'update', 'shareId' => 'ocinternal:7', 'before' => ['bits' => 19, 'note' => 'oi']];
        self::assertSame($this->states()->of('files_share', 'alice', $shown), $this->states()->of('files_share', 'alice', $shown));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->states()->of('files_share', 'alice', $shown));
    }

    public function testAnyDifferenceGivesAnotherValue(): void {
        $states = $this->states();
        $base = $states->of('files_share', 'alice', ['action' => 'update', 'shareId' => 'ocinternal:7', 'before' => ['bits' => 19]]);
        self::assertNotSame($base, $states->of('files_share', 'alice', ['action' => 'create', 'shareId' => 'ocinternal:7', 'before' => ['bits' => 19]]));
        self::assertNotSame($base, $states->of('files_share', 'alice', ['action' => 'update', 'shareId' => 'ocinternal:8', 'before' => ['bits' => 19]]));
        self::assertNotSame($base, $states->of('files_share', 'alice', ['action' => 'update', 'shareId' => 'ocinternal:7', 'before' => ['bits' => 17]]));
        self::assertNotSame($base, $states->of('files_unshare', 'alice', ['action' => 'update', 'shareId' => 'ocinternal:7', 'before' => ['bits' => 19]]),
            'o estado de uma tool não confirma outra');
        self::assertNotSame($base, $this->states('other-secret')->of('files_share', 'alice', ['action' => 'update', 'shareId' => 'ocinternal:7', 'before' => ['bits' => 19]]),
            'sem o segredo da instância não se calcula o valor');
    }

    /** The same state seen by another account is another value: a plan_state never confirms for somebody else. */
    public function testAnotherAccountGivesAnotherValue(): void {
        $states = $this->states();
        $shown = ['action' => 'update', 'shareId' => 'ocinternal:7', 'before' => ['bits' => 19]];
        self::assertNotSame($states->of('files_share', 'alice', $shown), $states->of('files_share', 'bruno', $shown));
    }

    /** Nothing of what was hashed can be read back from the value. */
    public function testTheValueCarriesNoneOfTheFields(): void {
        $value = $this->states()->of('files_share', 'alice', ['note' => 'segredo-da-nota', 'shareId' => 'ocinternal:42']);
        self::assertStringNotContainsString('segredo', $value);
        self::assertStringNotContainsString('ocinternal', $value);
        self::assertSame(64, strlen($value));
    }

    public function testTheValueGivenBackMatchesOnlyWhenIdentical(): void {
        $states = $this->states();
        $value = $states->of('files_share', 'alice', ['action' => 'create']);
        self::assertTrue(PlanState::matches($value, [PlanState::ARGUMENT => $value]));
        self::assertFalse(PlanState::matches($value, [PlanState::ARGUMENT => strtoupper($value)]));
        self::assertFalse(PlanState::matches($value, [PlanState::ARGUMENT => $states->of('files_share', 'alice', ['action' => 'update'])]));
    }

    /** A confirmed call without the value is an argument error on the field, the G4 way, with a fixed rule. */
    public function testAConfirmWithoutTheValueIsAnArgumentError(): void {
        foreach ([[], [PlanState::ARGUMENT => ''], [PlanState::ARGUMENT => 12]] as $arguments) {
            try {
                PlanState::require($arguments);
                self::fail('no argument error');
            } catch (ArgumentValidationException $e) {
                self::assertSame('plan_state', $e->details()['field']);
                self::assertSame('required with confirm: true; send the plan_state of the plan', $e->details()['rule']);
            }
        }
        PlanState::require([PlanState::ARGUMENT => 'abc']);
        $this->addToAssertionCount(1);
    }

    public function testThePublishedPropertyIsABoundedString(): void {
        $property = PlanState::property();
        self::assertSame('string', $property['type']);
        self::assertSame(1, $property['minLength']);
        self::assertSame(128, $property['maxLength']);
        self::assertStringContainsString('confirm: true', $property['description']);
    }
}
