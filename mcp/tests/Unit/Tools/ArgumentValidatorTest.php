<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use InvalidArgumentException;
use OCA\Mcp\Tools\ArgumentValidator;
use PHPUnit\Framework\TestCase;

/** Covers the list-of-types form (e.g. ["string", "null"]) used by optional nullable arguments. */
final class ArgumentValidatorTest extends TestCase {
    /** @return array<string, mixed> schema with a nullable string and no default */
    private function schema(): array {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'duedate' => ['type' => ['string', 'null'], 'maxLength' => 10],
        ]];
    }

    public function testNullableAcceptsNullStringAndAbsence(): void {
        $this->assertSame(['duedate' => null], ArgumentValidator::validate($this->schema(), ['duedate' => null]));
        $this->assertSame(['duedate' => '2026-10-01'], ArgumentValidator::validate($this->schema(), ['duedate' => '2026-10-01']));
        $this->assertSame([], ArgumentValidator::validate($this->schema(), []));
    }

    public function testNullableRejectsOtherTypesAndBrokenConstraints(): void {
        foreach ([5, true, '2026-10-01-extra'] as $value) {
            try {
                ArgumentValidator::validate($this->schema(), ['duedate' => $value]);
                $this->fail('accepted ' . var_export($value, true));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid argument: duedate', $e->getMessage());
            }
        }
    }

    public function testConstStillApplies(): void {
        $schema = ['properties' => ['confirm' => ['type' => 'boolean', 'const' => true]], 'required' => ['confirm']];
        $this->assertSame(['confirm' => true], ArgumentValidator::validate($schema, ['confirm' => true]));
        $this->expectException(InvalidArgumentException::class);
        ArgumentValidator::validate($schema, ['confirm' => false]);
    }
}
