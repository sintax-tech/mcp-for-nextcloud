<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Execution;

use OCA\Deck\ArchivedItemException;
use OCA\Deck\BadRequestException;
use OCA\Deck\Exceptions\ConflictException;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Modules\Deck\Execution\DeckConflictException;
use OCA\Mcp\Modules\Deck\Execution\DeckErrors;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers the exception to message mapping that keeps Deck internals out of the answer.
 */

final class DeckErrorsTest extends TestCase {
    /**
     * @return array<string, array{\Throwable, string}>
     */
    public static function mappingProvider(): array {
        return [
            'no permission' => [
                new NoPermissionException('Permission denied'),
                \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(),
            ],
            'does not exist' => [
                new DoesNotExistException('gone'),
                \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(),
            ],
            'multiple objects' => [
                new MultipleObjectsReturnedException('ambiguous'),
                \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(),
            ],
            'archived status' => [
                new StatusException('board archived'),
                \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotAllowed(),
            ],
            'archived item' => [
                new ArchivedItemException('card archived'),
                \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotAllowed(),
            ],
            'bad request' => [new BadRequestException('too long'), \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorInvalid()],
            'conflict' => [new ConflictException('stale'), \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorConflict()],
            'own conflict' => [
                new DeckConflictException(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorConflict()),
                \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorConflict(),
            ],
            'session not bound' => [
                new DeckSessionException('session user differs'),
                \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorSessionNotBound(),
            ],
            'unexpected' => [
                new RuntimeException('connection to 10.0.0.5 refused'),
                \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(),
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mappingProvider')]
    public function testMessageFor(\Throwable $exception, string $expected): void {
        self::assertSame($expected, DeckErrors::messageFor($exception));
    }

    public function testNotFoundAndNoPermissionShareOneMessage(): void {
        self::assertSame(
            DeckErrors::messageFor(new NoPermissionException('Permission denied')),
            DeckErrors::messageFor(new DoesNotExistException('gone')),
        );
    }

    public function testGenericMessageLeaksNoDetail(): void {
        $message = DeckErrors::messageFor(new RuntimeException('SELECT * FROM deck_cards failed at /srv/data'));
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $message);
        self::assertStringNotContainsString('deck_cards', $message);
        self::assertStringNotContainsString('/srv/data', $message);
    }
}
