<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use InvalidArgumentException;

/**
 * Builds the envelope Talk itself uses to render an attachment card in the chat.
 *
 * Only talk_quote_file needs it: on talk_attach_file the card is published by the Talk listener that reacts to the
 * share, and building a second envelope here would post the same attachment twice.
 */
class AttachmentMessage {
    public const MAX_CAPTION = 4000;

    /**
     * @param int $shareId Id of the room share that holds the file
     * @param string|null $caption Optional text rendered with the card
     * @return string The envelope JSON, with metaData.caption only when there is a caption
     * @throws InvalidArgumentException When the caption is blank or longer than self::MAX_CAPTION
     */
    public function build(int $shareId, ?string $caption = null): string {
        $parameters = ['share' => (string)$shareId];
        $text = self::normalizeCaption($caption);
        if ($text !== null) {
            $parameters['metaData'] = ['caption' => $text];
        }

        $envelope = json_encode(
            ['message' => 'file_shared', 'parameters' => $parameters],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($envelope === false) {
            throw new InvalidArgumentException(Messages::INVALID_IDENTIFIER);
        }

        return $envelope;
    }

    /**
     * The caption as the card will render it: surrounding whitespace removed, absent caption reported as null so
     * the caller does not have to distinguish "no caption" from "empty caption". A draft asks for the very text
     * that will be published, so the rule lives here and the preview uses it too.
     *
     * @param string|null $caption Caption as received from the client
     * @return string|null The trimmed caption, or null when there is none
     * @throws InvalidArgumentException When the caption is blank or too long
     */
    public static function normalizeCaption(?string $caption): ?string {
        if ($caption === null) {
            return null;
        }
        $trimmed = trim($caption);
        if ($trimmed === '') {
            throw new InvalidArgumentException(Messages::EMPTY_MESSAGE);
        }
        if (mb_strlen($trimmed) > self::MAX_CAPTION) {
            throw new InvalidArgumentException(Messages::MESSAGE_TOO_LONG);
        }

        return $trimmed;
    }
}
