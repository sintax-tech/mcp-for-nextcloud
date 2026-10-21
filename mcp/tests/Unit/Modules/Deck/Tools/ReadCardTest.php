<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use OCA\Deck\BadRequestException;
use OCA\Deck\NoPermissionException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatter;
use OCA\Mcp\Modules\Deck\Reading\CardReader;
use OCA\Mcp\Modules\Deck\Tools\ReadCard;
use OCA\Mcp\Tests\Support\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers `deck_read_card`.
 */

final class ReadCardTest extends TestCase {
    use DeckTestHelpers;
    /**
     * @param \Throwable|null $failure Exception the capability raises, or null for a happy path.
     * @return ReadCard Handler under test.
     */
    private function handler(?\Throwable $failure = null): ReadCard {
        $capabilities = $this->createMock(CardReader::class);
        $expectation = $capabilities->method('findCard');
        if ($failure !== null) {
            $expectation->willThrowException($failure);
        } else {
            $expectation->willReturn($this->card(['id' => 7, 'title' => 'Fechar contrato', 'description' => 'Com o cliente', 'attachmentCount' => 2]));
        }
        return new ReadCard(
            $capabilities->findCard(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
    }

    public function testHappyPathForwardsTheCardAndTheCaller(): void {
        $capabilities = $this->createMock(CardReader::class);
        $capabilities->expects(self::once())->method('findCard')->with('alice', 7)->willReturn($this->card(['id' => 7, 'title' => 'Fechar contrato']));
        $handler = new ReadCard(
            $capabilities->findCard(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        $payload = $this->payload($handler->execute(['cardId' => 7], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(7, $payload['id']);
        self::assertSame('Fechar contrato', $payload['title']);
    }

    public function testDescriptionIsReturnedInFull(): void {
        $description = str_repeat('linha\n', 500);
        $payload = $this->payload($this->handlerWithDescription($description)->execute(['cardId' => 7], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame($description, $payload['description']);
    }

    public function testDeniedAccessBecomesTheSameMessageAsAMissingCard(): void {
        $denied = $this->handler(new NoPermissionException('Permission denied'))->execute(['cardId' => 7], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        $missing = $this->handler(new DoesNotExistException('gone'))->execute(['cardId' => 7], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($denied['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($denied));
        self::assertSame($this->text($denied), $this->text($missing));
    }

    public function testDeckValidationFailureBecomesTheInvalidMessage(): void {
        $result = $this->handler(new BadRequestException('title too long'))->execute(['cardId' => 7], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorInvalid(), $this->text($result));
    }

    public function testBackendFailureBecomesTheGenericMessage(): void {
        $result = $this->handler(new RuntimeException('boom'))->execute(['cardId' => 7], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $this->text($result));
    }

    /**
     * @param string $description Description the returned card carries.
     * @return ReadCard Handler under test.
     */
    private function handlerWithDescription(string $description): ReadCard {
        $capabilities = $this->createMock(CardReader::class);
        $capabilities->method('findCard')->willReturn($this->card(['id' => 7, 'description' => $description]));
        return new ReadCard(
            $capabilities->findCard(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
    }
}
