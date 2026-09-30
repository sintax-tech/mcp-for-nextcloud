<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use InvalidArgumentException;
use OCA\Mcp\Tools\ArgumentValidator;
use PHPUnit\Framework\TestCase;

/** Covers the list-of-types form (e.g. ["string", "null"]) plus the nested objects and arrays the batch tools need. */
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

    /** @return array<string, mixed> a schema with one nested object and one array of objects, as talk_send_batch needs */
    private function batchSchema(): array {
        return ['properties' => [
            'target' => [
                'type' => 'object',
                'properties' => [
                    'user' => ['type' => 'string', 'maxLength' => 64],
                    'kind' => ['type' => 'string', 'enum' => ['deck_card', 'url']],
                ],
                'required' => ['user'],
            ],
            'items' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 2,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string', 'minLength' => 1],
                        'reply_to' => ['type' => 'integer', 'minimum' => 1, 'default' => 0],
                    ],
                    'required' => ['message'],
                ],
            ],
        ], 'required' => ['items']];
    }

    public function testNestedObjectAndArrayOfObjectsAreValidated(): void {
        $out = ArgumentValidator::validate($this->batchSchema(), [
            'target' => ['user' => 'alice', 'kind' => 'deck_card'],
            'items' => [['message' => 'oi'], ['message' => 'tchau', 'reply_to' => 7]],
        ]);

        $this->assertSame(['user' => 'alice', 'kind' => 'deck_card'], $out['target']);
        $this->assertSame([['message' => 'oi', 'reply_to' => 0], ['message' => 'tchau', 'reply_to' => 7]], $out['items']);
    }

    public function testAnAbsentNestedObjectStaysAbsentAndGainsNoDefaults(): void {
        $this->assertSame(['items' => [['message' => 'oi', 'reply_to' => 0]]], ArgumentValidator::validate($this->batchSchema(), ['items' => [['message' => 'oi']]]));
    }

    public function testUnknownKeyInsideANestedObjectNamesThePath(): void {
        try {
            ArgumentValidator::validate($this->batchSchema(), ['items' => [['message' => 'oi', 'surprise' => 1]]]);
            $this->fail('accepted an unknown nested key');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Unknown argument: items[0].surprise', $e->getMessage());
        }
    }

    public function testMissingRequiredKeyInsideAnArrayElementNamesThePath(): void {
        try {
            ArgumentValidator::validate($this->batchSchema(), ['items' => [['message' => 'oi'], ['reply_to' => 3]]]);
            $this->fail('accepted an array element without its required key');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Missing argument: items[1].message', $e->getMessage());
        }
    }

    public function testBrokenScalarInsideAnArrayElementNamesThePath(): void {
        try {
            ArgumentValidator::validate($this->batchSchema(), ['items' => [['message' => 'oi'], ['message' => 'tchau', 'reply_to' => 0]]]);
            $this->fail('accepted reply_to below the minimum');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid argument: items[1].reply_to', $e->getMessage());
        }
    }

    public function testEnumRefusesAValueOutsideTheList(): void {
        try {
            ArgumentValidator::validate($this->batchSchema(), ['target' => ['user' => 'alice', 'kind' => 'calendar_event'], 'items' => [['message' => 'oi']]]);
            $this->fail('accepted a kind outside the enum');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid argument: target.kind', $e->getMessage());
        }
    }

    public function testArrayBoundariesAreEnforced(): void {
        try {
            ArgumentValidator::validate($this->batchSchema(), ['items' => [['message' => 'a'], ['message' => 'b'], ['message' => 'c']]]);
            $this->fail('accepted more items than maxItems');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid argument: items', $e->getMessage());
        }

        try {
            ArgumentValidator::validate($this->batchSchema(), ['items' => []]);
            $this->fail('accepted fewer items than minItems');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid argument: items', $e->getMessage());
        }
    }

    public function testAListIsNotAnObjectAndAnObjectIsNotAList(): void {
        try {
            ArgumentValidator::validate($this->batchSchema(), ['target' => ['alice'], 'items' => [['message' => 'oi']]]);
            $this->fail('accepted a list where an object was declared');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid argument: target', $e->getMessage());
        }

        try {
            ArgumentValidator::validate($this->batchSchema(), ['items' => ['message' => 'oi']]);
            $this->fail('accepted an object where a list was declared');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid argument: items', $e->getMessage());
        }
    }

    public function testScalarAndStringValuesAreStillRefusedForStructuredProperties(): void {
        foreach (['items', 'target'] as $key) {
            try {
                ArgumentValidator::validate($this->batchSchema(), [$key => 'nope', 'items' => []]);
                $this->fail('accepted a string for ' . $key);
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid argument: ' . $key, $e->getMessage());
            }
        }
    }

    public function testAListOfArgumentsIsStillRefusedAtTheRoot(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid arguments');
        ArgumentValidator::validate($this->batchSchema(), [['message' => 'oi']]);
    }

    public function testConstStillApplies(): void {
        $schema = ['properties' => ['confirm' => ['type' => 'boolean', 'const' => true]], 'required' => ['confirm']];
        $this->assertSame(['confirm' => true], ArgumentValidator::validate($schema, ['confirm' => true]));
        $this->expectException(InvalidArgumentException::class);
        ArgumentValidator::validate($schema, ['confirm' => false]);
    }
}
