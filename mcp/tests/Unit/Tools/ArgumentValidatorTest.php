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

    /** The batch tool needs a list of {from,to} objects, so the registry validates them, not the handler. */
    public function testValidatesAListOfObjects(): void {
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'moves' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 200, 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string']],
                'required' => ['from', 'to'],
            ]],
        ]];
        $this->assertSame([['from' => '/a', 'to' => '/b']], ArgumentValidator::validate($schema, ['moves' => [['from' => '/a', 'to' => '/b']]])['moves']);
        foreach ([[['from' => '/a']], [['from' => '/a', 'to' => '/b', 'extra' => 1]], [['from' => 1, 'to' => '/b']], 'nao-lista'] as $moves) {
            try {
                ArgumentValidator::validate($schema, ['moves' => $moves]);
                $this->fail('accepted ' . json_encode($moves));
            } catch (InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/^(Invalid|Missing) argument: moves$/', $e->getMessage());
            }
        }
    }

    public function testEnforcesItemCountBounds(): void {
        $schema = ['type' => 'object', 'properties' => ['paths' => ['type' => 'array', 'maxItems' => 2]]];
        $this->assertSame(['paths' => ['/a', '/b']], ArgumentValidator::validate($schema, ['paths' => ['/a', '/b']]));
        $this->expectException(InvalidArgumentException::class);
        ArgumentValidator::validate($schema, ['paths' => ['/a', '/b', '/c']]);
    }

    /** An object property applies defaults and rejects unknown keys, exactly like a top-level schema. */
    public function testValidatesAnObjectProperty(): void {
        $schema = ['type' => 'object', 'properties' => ['payload' => [
            'type' => 'object', 'additionalProperties' => false, 'properties' => ['name' => ['type' => 'string']],
        ]]];
        $this->assertSame(['payload' => ['name' => 'x']], ArgumentValidator::validate($schema, ['payload' => ['name' => 'x']]));
        $this->expectException(InvalidArgumentException::class);
        ArgumentValidator::validate($schema, ['payload' => ['outro' => 'x']]);
    }

    public function testConstStillApplies(): void {
        $schema = ['properties' => ['confirm' => ['type' => 'boolean', 'const' => true]], 'required' => ['confirm']];
        $this->assertSame(['confirm' => true], ArgumentValidator::validate($schema, ['confirm' => true]));
        $this->expectException(InvalidArgumentException::class);
        ArgumentValidator::validate($schema, ['confirm' => false]);
    }
}
